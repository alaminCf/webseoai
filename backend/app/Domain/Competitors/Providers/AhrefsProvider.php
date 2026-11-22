<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class AhrefsProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim((string) config('competitors.providers.ahrefs.api_key', ''));
        $this->baseUrl = rtrim((string) config('competitors.providers.ahrefs.base_url', 'https://api.ahrefs.com/v3'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    public function getName(): string
    {
        return 'ahrefs';
    }

    public function getLabel(): string
    {
        return 'Ahrefs API v3';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['domain_metrics', 'backlinks'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => 'Ahrefs adapter focuses on Domain Rating & Backlink intelligence.'];
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => 'AHREFS_API_KEY is not configured.'];
        }

        try {
            $date = now()->subDay()->toDateString();
            $drRes = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)
                ->get("{$this->baseUrl}/site-explorer/domain-rating", ['target' => $domain, 'date' => $date]);
            $blRes = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)
                ->get("{$this->baseUrl}/site-explorer/backlinks-stats", ['target' => $domain, 'date' => $date]);

            if (!$drRes->successful() && !$blRes->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'http_status' => $drRes->status(), 'error' => 'Ahrefs API request failed (HTTP ' . $drRes->status() . ')'];
            }

            $dr = $drRes->json('domain_rating.domain_rating');
            $metrics = $blRes->json('metrics') ?? [];

            return [
                'available' => true,
                'provider' => $this->getName(),
                'classification' => 'third_party_estimate',
                'http_status' => $drRes->status(),
                'metrics' => [
                    'organic_traffic_estimate' => null,
                    'organic_keywords_count' => null,
                    'traffic_value_estimate' => null,
                    'referring_domains_count' => isset($metrics['live_refdomains']) ? (int) $metrics['live_refdomains'] : null,
                    'backlinks_count' => isset($metrics['live']) ? (int) $metrics['live'] : null,
                    'indexed_pages_count' => null,
                    'authority_score' => $dr !== null ? (int) round((float) $dr) : null,
                    'authority_metric_label' => 'Ahrefs Domain Rating (DR)',
                    'serp_visibility_score' => null,
                    'ranking_distribution' => null,
                    'top_keywords' => [],
                    'top_pages' => [],
                ],
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'Use DataForSEO or Semrush for organic keyword gap datasets.'];
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => 'AHREFS_API_KEY is not configured.'];
        }

        try {
            $res = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)
                ->get("{$this->baseUrl}/site-explorer/all-backlinks", [
                    'target' => $domain,
                    'select' => 'url_from,url_to,anchor,is_nofollow,domain_rating_source,first_seen,last_seen',
                    'limit' => min(100, max(10, $limit)),
                ]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'http_status' => $res->status(), 'error' => 'Ahrefs backlinks request failed (HTTP ' . $res->status() . ')'];
            }

            $rows = $res->json('backlinks') ?? [];
            $backlinks = [];
            foreach ($rows as $row) {
                $urlFrom = (string) ($row['url_from'] ?? '');
                $refDomain = strtolower((string) parse_url($urlFrom, PHP_URL_HOST));
                $refDomain = preg_replace('/^www\./i', '', $refDomain);
                if ($refDomain === '') {
                    continue;
                }
                $backlinks[] = [
                    'referring_domain' => $refDomain,
                    'referring_url' => $urlFrom,
                    'destination_url' => $row['url_to'] ?? null,
                    'anchor_text' => $row['anchor'] ?? null,
                    'link_type' => !empty($row['is_nofollow']) ? 'nofollow' : 'dofollow',
                    'referring_authority' => isset($row['domain_rating_source']) ? (int) round((float) $row['domain_rating_source']) : null,
                    'link_status' => 'active',
                    'first_seen_at' => $row['first_seen'] ?? null,
                    'last_seen_at' => $row['last_seen'] ?? null,
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'summary' => [
                    'referring_domains_count' => count(array_unique(array_column($backlinks, 'referring_domain'))),
                    'backlinks_count' => count($backlinks),
                    'authority_metric_label' => 'Ahrefs Domain Rating (DR)',
                ],
                'backlinks' => $backlinks,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'Ahrefs adapter does not support SERP snapshots.'];
    }
}
