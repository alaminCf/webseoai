<?php

namespace App\Domain\Crawler;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class PageInspector
{
    /**
     * Common anti-bot / WAF interstitial signatures so we NEVER falsely audit a challenge gate
     * as if it were the website's genuine SEO content.
     */
    private const WAF_TITLE_PATTERNS = [
        'one moment, please',
        'just a moment...',
        'attention required! | cloudflare',
        'access denied',
        'ddos-guard',
        'checking your browser',
        'verify you are human',
        'bot verification',
        'security check',
        'please wait while your request is being verified',
    ];

    private const WAF_BODY_PATTERNS = [
        'cf-browser-verification',
        'cdn-cgi/challenge-platform',
        'turnstile/v0/api.js',
        '_cf_chl_opt',
        'imunify360',
        'px-captcha',
        'perimeterx',
        'incapsula incident id',
        'sucuri website firewall',
        'recaptcha/api.js',
        'hcaptcha.com/1/api.js',
    ];

    /**
     * Inspect HTML content and HTTP headers to extract comprehensive SEO & technical metadata.
     */
    public static function inspect(
        string $html,
        string $pageUrl,
        string $rootDomain,
        array $headers = [],
        int $statusCode = 200,
        int $loadTimeMs = 0,
        ?string $effectiveBaseUrl = null
    ): array {
        $baseUrl = $effectiveBaseUrl ?: $pageUrl;
        $normalizedHeaders = self::normalizeHeaders($headers);
        $contentType = $normalizedHeaders['content-type'] ?? 'text/html';
        $isHttps = str_starts_with(strtolower($baseUrl), 'https://');

        $data = [
            'url' => $pageUrl,
            'final_url' => $baseUrl,
            'status_code' => $statusCode,
            'load_time_ms' => $loadTimeMs,
            'ttfb_ms' => $loadTimeMs,
            'html_size_bytes' => strlen($html),
            'content_type' => substr($contentType, 0, 100),
            'page_title' => null,
            'meta_description' => null,
            'canonical_url' => null,
            'viewport' => null,
            'html_lang' => null,
            'charset' => null,
            'favicon_url' => null,
            'open_graph' => [
                'title' => null,
                'description' => null,
                'image' => null,
                'type' => null,
                'url' => null,
            ],
            'twitter_card' => [
                'card' => null,
                'title' => null,
                'description' => null,
                'image' => null,
            ],
            'structured_data' => [
                'count' => 0,
                'types' => [],
                'invalid_blocks' => 0,
            ],
            'hreflang_tags' => [],
            'h1_tags' => [],
            'headings_tree' => [],
            'meta_robots' => null,
            'x_robots_tag' => null,
            'meta_refresh' => null,
            'is_indexable' => $statusCode === 200,
            'is_js_rendered' => false,
            'is_spa_shell' => false,
            'is_blocked_by_waf' => false,
            'word_count' => 0,
            'content_hash' => null,
            'images_count' => 0,
            'images_missing_alt' => 0,
            'images_without_dimensions' => 0,
            'images_missing_alt_samples' => [],
            'internal_links' => [],
            'external_links' => [],
            'raw_headers' => $headers,
            'security_headers' => [
                'https' => $isHttps,
                'hsts' => isset($normalizedHeaders['strict-transport-security']),
                'x_content_type_options' => isset($normalizedHeaders['x-content-type-options']),
                'x_frame_options' => isset($normalizedHeaders['x-frame-options']),
                'csp' => isset($normalizedHeaders['content-security-policy']),
                'referrer_policy' => isset($normalizedHeaders['referrer-policy']),
            ],
            'mixed_content_urls' => [],
        ];

        // Process HTTP headers for X-Robots-Tag
        if (isset($normalizedHeaders['x-robots-tag'])) {
            $xRobots = $normalizedHeaders['x-robots-tag'];
            $data['x_robots_tag'] = substr($xRobots, 0, 100);
            if (stripos($xRobots, 'noindex') !== false || stripos($xRobots, 'none') !== false) {
                $data['is_indexable'] = false;
            }
        }

        // Non-HTML or empty response
        if (empty(trim($html)) || stripos($contentType, 'html') === false) {
            if (in_array($statusCode, [403, 429, 503], true)) {
                $data['is_blocked_by_waf'] = self::detectWafChallenge($html, '', $statusCode, $normalizedHeaders);
            }
            return $data;
        }

        try {
            $crawler = new Crawler($html, $baseUrl);

            // 1. HTML Lang attribute
            $htmlNode = $crawler->filter('html');
            if ($htmlNode->count() > 0) {
                $lang = trim((string) ($htmlNode->first()->attr('lang') ?? ''));
                if ($lang !== '') {
                    $data['html_lang'] = substr($lang, 0, 32);
                }
            }

            // 2. Page Title
            $titleNode = $crawler->filter('title');
            if ($titleNode->count() > 0) {
                $title = self::cleanWhitespace($titleNode->first()->text(''));
                $data['page_title'] = $title !== '' ? $title : null;
            }

            // 3. Detect anti-bot / WAF challenge interstitial BEFORE auditing content
            if (self::detectWafChallenge($html, $data['page_title'] ?? '', $statusCode, $normalizedHeaders)) {
                $data['is_blocked_by_waf'] = true;
                $data['is_indexable'] = false;
                return $data;
            }

            // 4. Meta Tags (Description, Robots, Viewport, Charset, OG, Twitter, Refresh)
            $crawler->filter('meta')->each(function (Crawler $node) use (&$data) {
                $charsetAttr = trim((string) ($node->attr('charset') ?? ''));
                if ($charsetAttr !== '' && $data['charset'] === null) {
                    $data['charset'] = substr($charsetAttr, 0, 32);
                }

                $httpEquiv = strtolower(trim((string) ($node->attr('http-equiv') ?? '')));
                $name = strtolower(trim((string) ($node->attr('name') ?? '')));
                $property = strtolower(trim((string) ($node->attr('property') ?? '')));
                $content = self::cleanWhitespace((string) ($node->attr('content') ?? ''));

                if ($httpEquiv === 'content-type' && $data['charset'] === null && preg_match('/charset=([^\s;]+)/i', $content, $m)) {
                    $data['charset'] = substr($m[1], 0, 32);
                }

                if ($httpEquiv === 'refresh' && $content !== '' && $data['meta_refresh'] === null) {
                    $data['meta_refresh'] = substr($content, 0, 255);
                }

                // Strictly only <meta name="description"> counts as meta description
                if ($name === 'description' && $data['meta_description'] === null && $content !== '') {
                    $data['meta_description'] = $content;
                }

                if ($name === 'viewport' && $data['viewport'] === null && $content !== '') {
                    $data['viewport'] = substr($content, 0, 255);
                }

                if (($name === 'robots' || $name === 'googlebot') && $content !== '') {
                    if ($name === 'robots' || $data['meta_robots'] === null) {
                        $data['meta_robots'] = substr($content, 0, 100);
                    }
                    if (stripos($content, 'noindex') !== false || stripos($content, 'none') !== false) {
                        $data['is_indexable'] = false;
                    }
                }

                // Open Graph
                if ($property === 'og:title' && $content !== '') $data['open_graph']['title'] = $content;
                if ($property === 'og:description' && $content !== '') $data['open_graph']['description'] = $content;
                if ($property === 'og:image' && $content !== '') $data['open_graph']['image'] = $content;
                if ($property === 'og:type' && $content !== '') $data['open_graph']['type'] = $content;
                if ($property === 'og:url' && $content !== '') $data['open_graph']['url'] = $content;

                // Twitter Card
                if (($name === 'twitter:card' || $property === 'twitter:card') && $content !== '') $data['twitter_card']['card'] = $content;
                if (($name === 'twitter:title' || $property === 'twitter:title') && $content !== '') $data['twitter_card']['title'] = $content;
                if (($name === 'twitter:description' || $property === 'twitter:description') && $content !== '') $data['twitter_card']['description'] = $content;
                if (($name === 'twitter:image' || $property === 'twitter:image') && $content !== '') $data['twitter_card']['image'] = $content;
            });

            // 5. Link Tags (Canonical, Favicon, Hreflang, Mixed Content Stylesheets)
            $mixedContent = [];
            $crawler->filter('link')->each(function (Crawler $node) use (&$data, &$mixedContent, $baseUrl, $isHttps) {
                $rel = strtolower(trim((string) ($node->attr('rel') ?? '')));
                $href = trim((string) ($node->attr('href') ?? ''));
                if ($href === '') {
                    return;
                }

                $relTokens = preg_split('/\s+/', $rel) ?: [];

                if (in_array('canonical', $relTokens, true) && $data['canonical_url'] === null) {
                    $data['canonical_url'] = UrlNormalizer::normalize($href, $baseUrl);
                }

                if ((in_array('icon', $relTokens, true) || $rel === 'shortcut icon') && $data['favicon_url'] === null) {
                    $data['favicon_url'] = UrlNormalizer::resolveRelativeUrl($href, $baseUrl) ?? $href;
                }

                if (in_array('alternate', $relTokens, true)) {
                    $hreflang = trim((string) ($node->attr('hreflang') ?? ''));
                    if ($hreflang !== '') {
                        $data['hreflang_tags'][] = [
                            'lang' => $hreflang,
                            'href' => UrlNormalizer::normalize($href, $baseUrl) ?? $href,
                        ];
                    }
                }

                if ($isHttps && in_array('stylesheet', $relTokens, true) && str_starts_with(strtolower($href), 'http://')) {
                    $mixedContent[] = $href;
                }
            });

            // 6. Structured Data (Schema.org JSON-LD)
            $sdTypes = [];
            $sdCount = 0;
            $sdInvalid = 0;
            $crawler->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$sdTypes, &$sdCount, &$sdInvalid) {
                $raw = trim($node->text(''));
                if ($raw === '') {
                    return;
                }
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $sdInvalid++;
                    return;
                }
                $sdCount++;
                self::extractSchemaTypes($decoded, $sdTypes);
            });
            $data['structured_data'] = [
                'count' => $sdCount,
                'types' => array_values(array_unique($sdTypes)),
                'invalid_blocks' => $sdInvalid,
            ];

            // 7. Headings (H1 - H6)
            $crawler->filter('h1, h2, h3, h4, h5, h6')->each(function (Crawler $node) use (&$data) {
                $tag = strtolower($node->nodeName());
                $level = (int) substr($tag, 1);
                $text = self::cleanWhitespace($node->text(''));

                if ($tag === 'h1' && $text !== '') {
                    $data['h1_tags'][] = $text;
                }

                if ($text !== '') {
                    $data['headings_tree'][] = [
                        'level' => $level,
                        'tag' => $tag,
                        'text' => mb_substr($text, 0, 200),
                    ];
                }
            });

            // 8. Images, Alt Attributes, Dimensions & Mixed Content
            $missingAltSamples = [];
            $crawler->filter('img')->each(function (Crawler $node) use (&$data, &$missingAltSamples, &$mixedContent, $baseUrl, $isHttps) {
                $src = trim((string) ($node->attr('src') ?? $node->attr('data-src') ?? ''));
                // Skip 1x1 tracking pixels / empty data placeholders
                if ($src !== '' && str_starts_with($src, 'data:image/gif;base64,R0lGOD')) {
                    return;
                }

                $data['images_count']++;

                $alt = $node->attr('alt');
                $role = strtolower((string) ($node->attr('role') ?? ''));
                $ariaHidden = strtolower((string) ($node->attr('aria-hidden') ?? ''));
                $isDecorative = ($role === 'presentation' || $role === 'none' || $ariaHidden === 'true');

                if (!$isDecorative && ($alt === null || trim($alt) === '')) {
                    $data['images_missing_alt']++;
                    if ($src !== '' && count($missingAltSamples) < 10) {
                        $resolvedSrc = UrlNormalizer::resolveRelativeUrl($src, $baseUrl) ?? $src;
                        $missingAltSamples[] = $resolvedSrc;
                    }
                }

                $width = $node->attr('width');
                $height = $node->attr('height');
                if (($width === null || trim($width) === '') && ($height === null || trim($height) === '')) {
                    $data['images_without_dimensions']++;
                }

                if ($isHttps && $src !== '' && str_starts_with(strtolower($src), 'http://')) {
                    $mixedContent[] = $src;
                }
            });
            $data['images_missing_alt_samples'] = array_values(array_unique($missingAltSamples));

            // Mixed content scripts
            if ($isHttps) {
                $crawler->filter('script[src]')->each(function (Crawler $node) use (&$mixedContent) {
                    $src = trim((string) ($node->attr('src') ?? ''));
                    if (str_starts_with(strtolower($src), 'http://')) {
                        $mixedContent[] = $src;
                    }
                });
            }
            $data['mixed_content_urls'] = array_slice(array_values(array_unique($mixedContent)), 0, 20);

            // 9. Internal and External Links (deduplicated per target URL)
            $internal = [];
            $external = [];
            $seenInternal = [];
            $seenExternal = [];

            $crawler->filter('a[href]')->each(function (Crawler $node) use ($baseUrl, $rootDomain, &$internal, &$external, &$seenInternal, &$seenExternal) {
                $href = $node->attr('href');
                if (!$href) return;

                $normalized = UrlNormalizer::normalize($href, $baseUrl);
                if (!$normalized) return;

                $anchorText = mb_substr(self::cleanWhitespace($node->text('')), 0, 120);
                $rel = strtolower(trim((string) ($node->attr('rel') ?? '')));

                if (UrlNormalizer::isInternal($normalized, $rootDomain)) {
                    if (!isset($seenInternal[$normalized])) {
                        $seenInternal[$normalized] = true;
                        $internal[] = [
                            'url' => $normalized,
                            'text' => $anchorText,
                            'rel' => $rel,
                        ];
                    }
                } else {
                    if (!isset($seenExternal[$normalized])) {
                        $seenExternal[$normalized] = true;
                        $external[] = [
                            'url' => $normalized,
                            'text' => $anchorText,
                            'rel' => $rel,
                        ];
                    }
                }
            });

            $data['internal_links'] = $internal;
            $data['external_links'] = $external;

            // 10. Genuine Visible Word Count & Content Fingerprint (strips script/style/noscript/svg!)
            $visibleText = self::extractVisibleBodyText($html);
            $data['word_count'] = self::countUnicodeWords($visibleText);

            if ($data['word_count'] >= 20) {
                $normalizedText = mb_strtolower(preg_replace('/\s+/u', ' ', trim($visibleText)));
                $data['content_hash'] = hash('sha256', $normalizedText);
            }

            // 11. Detect if raw HTML is an unhydrated CSR SPA shell
            $data['is_spa_shell'] = HeadlessRenderer::looksLikeSpaShell(
                $html,
                $data['word_count'],
                count($internal)
            );
        } catch (Throwable) {
            // Malformed HTML fallback: graceful extraction without crashing
        }

        return $data;
    }

    /**
     * Detect whether the HTTP response is an anti-bot / WAF challenge interstitial.
     */
    public static function detectWafChallenge(string $html, string $title, int $statusCode, array $normalizedHeaders = []): bool
    {
        $titleLower = strtolower(trim($title));
        foreach (self::WAF_TITLE_PATTERNS as $pattern) {
            if ($titleLower !== '' && str_contains($titleLower, $pattern)) {
                return true;
            }
        }

        if (isset($normalizedHeaders['cf-mitigated']) && stripos($normalizedHeaders['cf-mitigated'], 'challenge') !== false) {
            return true;
        }

        // Only match body WAF markers when status is blocked OR HTML is small interstitial (< 60KB)
        if (in_array($statusCode, [403, 429, 503], true) || strlen($html) < 60000) {
            $htmlLower = strtolower($html);
            foreach (self::WAF_BODY_PATTERNS as $marker) {
                if (str_contains($htmlLower, $marker)) {
                    // Make sure it's not just a normal page with a contact form reCAPTCHA
                    if ($marker === 'recaptcha/api.js' || $marker === 'hcaptcha.com/1/api.js') {
                        if (in_array($statusCode, [403, 429, 503], true) && strlen($html) < 25000) {
                            return true;
                        }
                        continue;
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Extract visible body text excluding script, style, noscript, svg, template tags.
     */
    protected static function extractVisibleBodyText(string $html): string
    {
        if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $matches)) {
            $bodyHtml = $matches[1];
        } else {
            $bodyHtml = $html;
        }

        // Strip non-visible executable/style/vector blocks
        $cleaned = preg_replace(
            '#<(script|style|noscript|svg|template|iframe)\b[^>]*>.*?</\1>#is',
            ' ',
            $bodyHtml
        );
        $cleaned = preg_replace('#<!--.*?-->#s', ' ', $cleaned ?? $bodyHtml);
        $text = strip_tags($cleaned ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::cleanWhitespace($text);
    }

    /**
     * Unicode-safe word counting (works for English, Bangla, Arabic, CJK, European languages).
     */
    protected static function countUnicodeWords(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        $count = preg_match_all('/[\p{L}\p{N}]+(?:[\'-][\p{L}\p{N}]+)*/u', $text);
        return $count === false ? 0 : $count;
    }

    protected static function cleanWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    protected static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $key = strtolower((string) $name);
            $normalized[$key] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $normalized;
    }

    protected static function extractSchemaTypes(array $node, array &$types): void
    {
        if (isset($node['@type'])) {
            if (is_array($node['@type'])) {
                foreach ($node['@type'] as $t) {
                    if (is_string($t) && $t !== '') {
                        $types[] = $t;
                    }
                }
            } elseif (is_string($node['@type']) && $node['@type'] !== '') {
                $types[] = $node['@type'];
            }
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) {
                    self::extractSchemaTypes($child, $types);
                }
            }
        }

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    self::extractSchemaTypes($item, $types);
                }
            }
        }
    }
}
