<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class SemrushProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim((string) config('competitors.providers.semrush.api_key', ''));
        $this->baseUrl = rtrim((string) config('competitors.providers.semrush.base_url', 'https://api.semrush.com'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    public function getName(): string
    {
        return 'semrush';
    }

    public function getLabel(): string
    {
        return 'Semrush Analytics API';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['discovery', 'domain_metrics', 'keywords', 'backlinks'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => 'SEMRUSH_API_KEY is not configured.'];
        }

        try {
            $res = Http::timeout($this->timeout)->get($this->baseUrl, [
                'type' => 'domain_organic_organic',
                'key' => $this->apiKey,
                'domain' => $domain,
                'database' => $this->db($country),
                'display_limit' => 20,
                'export_columns' => 'Dn,Cr,Np,Or,Ot',
            ]);

            if (!$res->successful() || str_starts_with(trim($res->body()), 'ERROR')) {
                return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'http_status' => $res->status(), 'error' => trim($res->body()) ?: 'Semrush discovery error'];
            }

            $rows = $this->parseSemicolonCsv($res->body());
            $candidates = [];
            foreach ($rows as $row) {
                $candDomain = strtolower(trim((string) ($row['Domain'] ?? '')));
                if ($candDomain === '' || $candDomain === strtolower($domain)) {
                    continue;
                }
                $candidates[] = [
                    'domain' => $candDomain,
                    'shared_keywords' => (int) ($row['Common Keywords'] ?? 0),
                    'serp_overlap_pct' => round(min(100, (float) ($row['Competitor Relevance'] ?? 0) * 100), 1),
                    'organic_traffic_estimate' => isset($row['Organic Traffic']) ? (int) $row['Organic Traffic'] : null,
                    'sample_urls' => [],
                    'source' => 'semrush',
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'methodology' => "Semrush Organic Competitors API ({$country}) based on common ranking keywords and competitor relevance score.",
                'candidates' => $candidates,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => 'SEMRUSH_API_KEY is not configured.'];
        }

        try {
            $res = Http::timeout($this->timeout)->get($this->baseUrl, [
                'type' => 'domain_ranks',
                'key' => $this->apiKey,
                'domain' => $domain,
                'database' => $this->db($country),
                'export_columns' => 'Dn,Rk,Or,Ot,Oc',
            ]);

            if (!$res->successful() || str_starts_with(trim($res->body()), 'ERROR')) {
                return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'http_status' => $res->status(), 'error' => trim($res->body()) ?: 'Semrush domain overview error'];
            }

            $rows = $this->parseSemicolonCsv($res->body());
            $row = $rows[0] ?? [];

            return [
                'available' => true,
                'provider' => $this->getName(),
                'classification' => 'third_party_estimate',
                'http_status' => $res->status(),
                'metrics' => [
                    'organic_traffic_estimate' => isset($row['Organic Traffic']) ? (int) $row['Organic Traffic'] : null,
                    'organic_keywords_count' => isset($row['Organic Keywords']) ? (int) $row['Organic Keywords'] : null,
                    'traffic_value_estimate' => isset($row['Organic Cost']) ? round((float) $row['Organic Cost'], 2) : null,
                    'referring_domains_count' => null,
                    'backlinks_count' => null,
                    'indexed_pages_count' => null,
                    'authority_score' => null,
                    'authority_metric_label' => 'Semrush Authority Score',
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
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'SEMRUSH_API_KEY is not configured.'];
        }

        try {
            $res = Http::timeout($this->timeout)->get($this->baseUrl, [
                'type' => 'domain_organic',
                'key' => $this->apiKey,
                'domain' => $domain,
                'database' => $this->db($country),
                'display_limit' => min(100, max(10, $limit)),
                'export_columns' => 'Ph,Po,Nq,Cp,Ur,Kd',
            ]);

            if (!$res->successful() || str_starts_with(trim($res->body()), 'ERROR')) {
                return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'http_status' => $res->status(), 'error' => trim($res->body()) ?: 'Semrush keyword error'];
            }

            $rows = $this->parseSemicolonCsv($res->body());
            $keywords = [];
            foreach ($rows as $row) {
                $kw = trim((string) ($row['Keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }
                $keywords[] = [
                    'keyword' => $kw,
                    'position' => isset($row['Position']) ? (int) $row['Position'] : null,
                    'search_volume' => isset($row['Search Volume']) ? (int) $row['Search Volume'] : null,
                    'cpc' => isset($row['CPC']) ? round((float) $row['CPC'], 2) : null,
                    'url' => $row['Url'] ?? null,
                    'keyword_difficulty' => isset($row['Keyword Difficulty']) ? (int) round((float) $row['Keyword Difficulty']) : null,
                    'search_intent' => null,
                    'serp_features' => [],
                ];
            }

            return ['available' => true, 'provider' => $this->getName(), 'http_status' => $res->status(), 'keywords' => $keywords];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => 'SEMRUSH_API_KEY is not configured.'];
        }

        try {
            $res = Http::timeout($this->timeout)->get($this->baseUrl . '/analytics/v1/', [
                'type' => 'backlinks_overview',
                'key' => $this->apiKey,
                'target' => $domain,
                'target_type' => 'root_domain',
                'export_columns' => 'ascore,total,domains_num',
            ]);

            if (!$res->successful() || str_starts_with(trim($res->body()), 'ERROR')) {
                return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'http_status' => $res->status(), 'error' => trim($res->body()) ?: 'Semrush backlinks error'];
            }

            $rows = $this->parseSemicolonCsv($res->body());
            $row = $rows[0] ?? [];

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'summary' => [
                    'referring_domains_count' => isset($row['domains_num']) ? (int) $row['domains_num'] : null,
                    'backlinks_count' => isset($row['total']) ? (int) $row['total'] : null,
                    'authority_score' => isset($row['ascore']) ? (int) $row['ascore'] : null,
                    'authority_metric_label' => 'Semrush Authority Score',
                ],
                'backlinks' => [],
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'Semrush adapter does not provide live SERP snapshots.'];
    }

    private function db(string $country): string
    {
        return (string) (config('competitors.country_map.' . strtoupper($country) . '.semrush_db') ?? 'us');
    }

    private function parseSemicolonCsv(string $body): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $body) ?: [])));
        if (count($lines) < 2) {
            return [];
        }
        $headers = str_getcsv($lines[0], ';');
        $out = [];
        for ($i = 1; $i < count($lines); $i++) {
            $cols = str_getcsv($lines[$i], ';');
            if (count($cols) === count($headers)) {
                $out[] = array_combine($headers, $cols);
            }
        }
        return $out;
    }
}
