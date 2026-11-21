<?php

namespace App\Domain\Crawler;

class UrlNormalizer
{
    /**
     * File extensions that are binary/media/static assets rather than crawlable HTML pages.
     */
    private const NON_HTML_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif', 'tiff',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'rtf',
        'zip', 'rar', 'tar', 'gz', '7z', 'dmg', 'exe', 'apk',
        'mp3', 'wav', 'ogg', 'mp4', 'webm', 'avi', 'mov', 'mkv', 'm3u8',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'css', 'js', 'mjs', 'map', 'json', 'xml', 'rss', 'atom', 'txt',
    ];

    /**
     * Tracking query parameters stripped during canonical URL deduplication.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl',
    ];

    /**
     * Normalize an absolute or relative URL relative to a base URL.
     */
    public static function normalize(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if (
            $url === '' ||
            str_starts_with($url, '#') ||
            preg_match('#^(mailto|tel|sms|javascript|data|blob|whatsapp|viber|skype):#i', $url)
        ) {
            return null;
        }

        // Remove hash fragments
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $url = substr($url, 0, $hashPos);
        }

        if ($url === '') {
            return null;
        }

        // Resolve relative to base URL
        $resolved = self::resolveRelativeUrl($url, $baseUrl);
        if (!$resolved) {
            return null;
        }

        $parts = parse_url($resolved);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $port = '';
        if (isset($parts['port'])) {
            $isDefaultPort = ($scheme === 'http' && $parts['port'] === 80)
                || ($scheme === 'https' && $parts['port'] === 443);
            if (!$isDefaultPort) {
                $port = ':' . $parts['port'];
            }
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        // Resolve dot segments (/./ and /../) and collapse duplicate slashes
        $path = self::resolveDotSegments($path);

        // Normalize trailing slash: retain on root '/', strip on non-root paths
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        // Query string normalization (strip tracking params, sort deterministically)
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $queryParams);
            foreach (self::TRACKING_PARAMS as $tp) {
                unset($queryParams[$tp]);
            }
            if (!empty($queryParams)) {
                ksort($queryParams);
                $query = '?' . http_build_query($queryParams);
            }
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    /**
     * Normalize a website's target_url into its canonical homepage URL (always with trailing '/').
     */
    public static function canonicalHomepageUrl(string $targetUrl): string
    {
        $normalized = self::normalize($targetUrl, $targetUrl);
        return $normalized ?? rtrim($targetUrl, '/') . '/';
    }

    /**
     * Resolve relative URL string into absolute URL based on parent page URL.
     */
    public static function resolveRelativeUrl(string $url, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }

        $baseScheme = strtolower($baseParts['scheme']);
        $baseHost = strtolower($baseParts['host']);
        $basePort = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $baseOrigin = "{$baseScheme}://{$baseHost}{$basePort}";

        // Protocol-relative (//example.com/page)
        if (str_starts_with($url, '//')) {
            return "{$baseScheme}:{$url}";
        }

        // Query-only relative (?page=2)
        if (str_starts_with($url, '?')) {
            $basePath = $baseParts['path'] ?? '/';
            return $baseOrigin . ($basePath === '' ? '/' : $basePath) . $url;
        }

        // Root-relative (/path)
        if (str_starts_with($url, '/')) {
            return $baseOrigin . $url;
        }

        // Path-relative (subpage/doc or ../doc)
        $basePath = $baseParts['path'] ?? '/';
        if (str_ends_with($basePath, '/')) {
            $baseDir = $basePath;
        } else {
            $dir = dirname($basePath);
            $baseDir = ($dir === '.' || $dir === '\\' || $dir === '/') ? '/' : rtrim($dir, '/') . '/';
        }

        return $baseOrigin . $baseDir . $url;
    }

    /**
     * Resolve RFC 3986 dot segments ('.' and '..') and collapse duplicate slashes.
     */
    protected static function resolveDotSegments(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path);
        $segments = explode('/', $path);
        $output = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($output);
                continue;
            }
            $output[] = $segment;
        }

        return '/' . implode('/', $output);
    }

    /**
     * Strip leading www. for consistent apex domain comparisons.
     */
    public static function apexDomain(string $hostOrDomain): string
    {
        $host = strtolower(trim($hostOrDomain));
        if (str_starts_with($host, 'www.')) {
            return substr($host, 4);
        }
        return $host;
    }

    /**
     * Check if target URL belongs to the same domain/subdomain (including www <-> apex) as root.
     */
    public static function isInternal(string $url, string $rootDomain): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }

        $host = strtolower($host);
        $root = self::apexDomain($rootDomain);

        return $host === $root || str_ends_with($host, '.' . $root);
    }

    /**
     * Determine whether a normalized URL looks like a crawlable HTML page rather than a binary/static file.
     */
    public static function isCrawlableHtmlUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $basename = basename($path);

        if ($basename !== '' && str_contains($basename, '.')) {
            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::NON_HTML_EXTENSIONS, true)) {
                return false;
            }
        }

        return true;
    }
}
