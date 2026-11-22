<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Domain\Crawler\PageInspector;
use App\Domain\Crawler\UrlSecurityValidator;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Always-available verified first-party public HTML & sitemap signal inspector.
 * NEVER fabricates third-party metrics (traffic estimates, search volume, KD, DR, or backlinks).
 * All unsupported third-party metrics remain strictly null.
 */
class FirstPartyCrawlProvider implements CompetitorProviderInterface
{
    public function getName(): string
    {
        return 'first_party_crawl';
    }

    public function getLabel(): string
    {
        return 'Web SEO AI First-Party Crawler (Verified HTML Signals)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedCapabilities(): array
    {
        return ['domain_metrics', 'discovery'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        return [
            'available' => true,
            'provider' => $this->getName(),
            'methodology' => 'External SERP overlap providers (DataForSEO / Semrush / SerpApi) are not configured yet. Candidates are sourced from explicit Brand Context competitors and manual entries only — no fabricated domains are invented.',
            'candidates' => [],
            'cost_usd' => 0.0,
        ];
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        $targetUrl = "https://{$domain}";
        $crawledSignals = [
            'reachable' => false,
            'http_status' => null,
            'page_title' => null,
            'meta_description' => null,
            'h1_tags' => [],
            'word_count' => null,
            'structured_data_types' => [],
            'internal_links_count' => null,
            'load_time_ms' => null,
            'has_https' => true,
            'has_sitemap' => false,
            'sitemap_urls_count' => null,
            'top_discovered_pages' => [],
        ];

        $topPages = [];

        try {
            if (!app()->runningUnitTests()) {
                $validation = UrlSecurityValidator::validate($targetUrl, $domain);
                if (!($validation['valid'] ?? false)) {
                    return [
                        'available' => false,
                        'provider' => $this->getName(),
                        'classification' => 'first_party_verified',
                        'metrics' => $this->emptyMetrics($crawledSignals),
                        'error' => 'Domain blocked by SSRF security policy: ' . ($validation['reason'] ?? 'invalid host'),
                    ];
                }
            }

            $start = microtime(true);
            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; WebSEOAI-CompetitorInspector/1.0)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])->timeout(10)->get($targetUrl);
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            $inspected = PageInspector::inspect($res->body(), $targetUrl, $domain, $res->headers(), $res->status(), $elapsed, $targetUrl);

            $crawledSignals['reachable'] = $res->successful();
            $crawledSignals['http_status'] = $res->status();
            $crawledSignals['load_time_ms'] = $elapsed;
            $crawledSignals['page_title'] = $inspected['page_title'] ?? null;
            $crawledSignals['meta_description'] = $inspected['meta_description'] ?? null;
            $crawledSignals['h1_tags'] = $inspected['h1_tags'] ?? [];
            $crawledSignals['word_count'] = $inspected['word_count'] ?? null;
            $crawledSignals['structured_data_types'] = $inspected['structured_data']['types'] ?? [];
            $crawledSignals['internal_links_count'] = $inspected['internal_links_count'] ?? 0;
            $crawledSignals['is_blocked_by_waf'] = (bool) ($inspected['is_blocked_by_waf'] ?? false);

            if ($res->successful() && !empty($inspected['page_title'])) {
                $topPages[] = [
                    'url' => $targetUrl,
                    'title' => $inspected['page_title'],
                    'h1' => $inspected['h1_tags'][0] ?? null,
                    'word_count' => $inspected['word_count'] ?? null,
                    'estimated_traffic' => null,
                    'source' => 'first_party_crawl',
                ];
            }

            // Collect prominent internal navigation URLs as verified public pages
            foreach (array_slice($inspected['internal_links'] ?? [], 0, 8) as $link) {
                $linkUrl = (string) ($link['url'] ?? '');
                $linkText = trim((string) ($link['text'] ?? ''));
                if ($linkUrl !== '' && $linkUrl !== $targetUrl && !str_contains($linkUrl, '#')) {
                    $topPages[] = [
                        'url' => $linkUrl,
                        'title' => $linkText !== '' ? $linkText : trim((string) parse_url($linkUrl, PHP_URL_PATH), '/'),
                        'estimated_traffic' => null,
                        'source' => 'first_party_crawl',
                    ];
                }
            }

            // Quick robots/sitemap check to measure public sitemap page count
            try {
                $smRes = Http::withHeaders(['User-Agent' => 'WebSEOAI-CompetitorInspector/1.0'])
                    ->timeout(6)
                    ->get("{$targetUrl}/sitemap.xml");
                if ($smRes->successful() && str_contains(strtolower($smRes->body()), '<loc>')) {
                    $crawledSignals['has_sitemap'] = true;
                    preg_match_all('/<loc>\s*(https?:\/\/[^<\s]+)\s*<\/loc>/i', $smRes->body(), $matches);
                    $locs = array_unique($matches[1] ?? []);
                    $crawledSignals['sitemap_urls_count'] = count($locs);
                    foreach (array_slice($locs, 0, 10) as $loc) {
                        if (!collect($topPages)->contains('url', $loc)) {
                            $topPages[] = [
                                'url' => $loc,
                                'title' => trim((string) parse_url($loc, PHP_URL_PATH), '/') ?: 'Homepage',
                                'estimated_traffic' => null,
                                'source' => 'first_party_sitemap',
                            ];
                        }
                    }
                }
            } catch (Throwable) {
                // Optional sitemap probe
            }

            $crawledSignals['top_discovered_pages'] = array_slice($topPages, 0, 12);

            return [
                'available' => true,
                'provider' => $this->getName(),
                'classification' => 'first_party_verified',
                'http_status' => $res->status(),
                'cost_usd' => 0.0,
                'metrics' => [
                    'organic_traffic_estimate' => null,
                    'organic_keywords_count' => null,
                    'traffic_value_estimate' => null,
                    'referring_domains_count' => null,
                    'backlinks_count' => null,
                    'indexed_pages_count' => $crawledSignals['sitemap_urls_count'],
                    'authority_score' => null,
                    'authority_metric_label' => null,
                    'serp_visibility_score' => null,
                    'ranking_distribution' => null,
                    'top_keywords' => [],
                    'top_pages' => array_slice($topPages, 0, 12),
                    'crawled_signals' => $crawledSignals,
                ],
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'classification' => 'first_party_verified',
                'metrics' => $this->emptyMetrics($crawledSignals),
                'error' => 'Could not inspect domain HTML: ' . $e->getMessage(),
                'cost_usd' => 0.0,
            ];
        }
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'keywords' => [],
            'error' => 'Third-party keyword provider (DataForSEO or Semrush) is required to retrieve competitor organic keyword rankings and search volume.',
        ];
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'summary' => [],
            'backlinks' => [],
            'error' => 'Backlink intelligence provider (DataForSEO Backlinks, Ahrefs API v3, or Semrush) is not configured.',
        ];
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'serp_features' => [],
            'organic_results' => [],
            'error' => 'SERP data provider (DataForSEO SERP API or SerpApi) is not configured.',
        ];
    }

    private function emptyMetrics(array $crawledSignals): array
    {
        return [
            'organic_traffic_estimate' => null,
            'organic_keywords_count' => null,
            'traffic_value_estimate' => null,
            'referring_domains_count' => null,
            'backlinks_count' => null,
            'indexed_pages_count' => null,
            'authority_score' => null,
            'authority_metric_label' => null,
            'serp_visibility_score' => null,
            'ranking_distribution' => null,
            'top_keywords' => [],
            'top_pages' => [],
            'crawled_signals' => $crawledSignals,
        ];
    }
}
