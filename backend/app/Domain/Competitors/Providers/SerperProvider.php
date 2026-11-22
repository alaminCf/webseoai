<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Serper.dev Google Search API adapter (2,500 free queries on signup).
 * Covers live Google Organic SERP snapshots and multi-query competitor discovery.
 */
class SerperProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim((string) config('competitors.providers.serper.api_key', ''));
        $this->baseUrl = rtrim((string) config('competitors.providers.serper.base_url', 'https://google.serper.dev'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    public function getName(): string
    {
        return 'serper';
    }

    public function getLabel(): string
    {
        return 'Serper.dev (Google Search API)';
    }

    public function isConfigured(): bool
    {
        $this->apiKey = trim((string) config('competitors.providers.serper.api_key', ''));
        return $this->apiKey !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['serp', 'discovery'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => 'SERPER_API_KEY is not configured.'];
        }

        $queries = array_slice(array_values(array_filter($context['seed_keywords'] ?? [])), 0, 4);
        if (empty($queries)) {
            $queries = [$context['industry'] ?? str_replace(['-', '.'], ' ', pathinfo($domain, PATHINFO_FILENAME))];
        }

        $domainStats = [];
        $targetNorm = preg_replace('/^www\./i', '', strtolower($domain));
        $succeededQueries = 0;
        $lastError = null;
        $totalCost = 0.0;

        foreach ($queries as $q) {
            $serp = $this->fetchSerp((string) $q, $country, $language, 'desktop');
            if (!($serp['available'] ?? false)) {
                $lastError = $serp['error'] ?? 'Serper.dev query failed.';
                continue;
            }
            $succeededQueries++;
            $totalCost += (float) ($serp['cost_usd'] ?? 0.001);
            foreach ($serp['organic_results'] as $row) {
                $d = preg_replace('/^www\./i', '', strtolower((string) ($row['domain'] ?? '')));
                if ($d === '' || $d === $targetNorm || !str_contains($d, '.') || str_ends_with($d, '.' . $targetNorm)) {
                    continue;
                }
                if (!isset($domainStats[$d])) {
                    $domainStats[$d] = [
                        'domain' => $d,
                        'shared_keywords' => 0,
                        'matched_queries' => [],
                        'positions' => [],
                        'sample_urls' => [],
                        'sample_title' => $row['title'] ?? null,
                        'sample_snippet' => $row['snippet'] ?? null,
                        'source' => 'serper',
                    ];
                }
                if (!in_array($q, $domainStats[$d]['matched_queries'], true)) {
                    $domainStats[$d]['matched_queries'][] = $q;
                    $domainStats[$d]['shared_keywords']++;
                }
                $domainStats[$d]['positions'][] = (int) ($row['position'] ?? 10);
                if (count($domainStats[$d]['sample_urls']) < 3 && !empty($row['url']) && !in_array($row['url'], $domainStats[$d]['sample_urls'], true)) {
                    $domainStats[$d]['sample_urls'][] = $row['url'];
                }
            }
        }

        if ($succeededQueries === 0) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'methodology' => 'Serper.dev Google Organic SERP discovery failed for queries: ' . implode(' | ', $queries),
                'candidates' => [],
                'queries_used' => $queries,
                'error' => $lastError ?: 'Serper.dev returned no successful search responses.',
                'cost_usd' => 0.0,
            ];
        }

        $totalQueries = max(1, $succeededQueries);
        $candidates = [];
        foreach ($domainStats as $stat) {
            $avgPos = round(array_sum($stat['positions']) / max(1, count($stat['positions'])), 1);
            $candidates[] = [
                'domain' => $stat['domain'],
                'shared_keywords' => $stat['shared_keywords'],
                'matched_queries' => $stat['matched_queries'],
                'avg_position' => $avgPos,
                'serp_overlap_pct' => round(($stat['shared_keywords'] / $totalQueries) * 100, 1),
                'sample_title' => $stat['sample_title'],
                'sample_snippet' => $stat['sample_snippet'],
                'sample_urls' => $stat['sample_urls'],
                'source' => 'serper',
            ];
        }

        usort($candidates, fn ($a, $b) => ($b['shared_keywords'] <=> $a['shared_keywords']) ?: ($a['avg_position'] <=> $b['avg_position']));

        return [
            'available' => true,
            'provider' => $this->getName(),
            'methodology' => 'Serper.dev live Google Organic SERP overlap across ' . $succeededQueries . " targeted service queries ({$country}/{$language}): " . implode(' | ', $queries),
            'candidates' => array_slice($candidates, 0, 25),
            'queries_used' => $queries,
            'cost_usd' => $totalCost,
        ];
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => 'Serper.dev provides SERP & discovery data.'];
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'Serper.dev does not provide domain-wide keyword tables.'];
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => 'Serper.dev does not provide backlink datasets.'];
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'SERPER_API_KEY is not configured.'];
        }

        try {
            $gl = (string) (config('competitors.country_map.' . strtoupper($country) . '.gl') ?? strtolower($country));
            $payload = [
                'q' => $keyword,
                'gl' => $gl,
                'hl' => strtolower($language),
                'num' => 20,
            ];
            if ($location) {
                $payload['location'] = $location;
            }

            $res = Http::withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->post("{$this->baseUrl}/search", $payload);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'http_status' => $res->status(), 'error' => 'Serper.dev request failed (HTTP ' . $res->status() . ')'];
            }

            $json = $res->json() ?? [];
            $features = [];
            foreach (['answerBox' => 'featured_snippet', 'peopleAlsoAsk' => 'people_also_ask', 'places' => 'local_pack', 'knowledgeGraph' => 'knowledge_panel', 'shopping' => 'shopping', 'videos' => 'video_carousel', 'topStories' => 'top_stories'] as $k => $label) {
                if (!empty($json[$k])) {
                    $features[] = $label;
                }
            }

            $organic = [];
            foreach ($json['organic'] ?? [] as $idx => $item) {
                $url = (string) ($item['link'] ?? '');
                $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
                $organic[] = [
                    'position' => (int) ($item['position'] ?? ($idx + 1)),
                    'domain' => $host,
                    'url' => $url,
                    'title' => (string) ($item['title'] ?? ''),
                    'snippet' => (string) ($item['snippet'] ?? ''),
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'cost_usd' => 0.001,
                'serp_features' => $features,
                'organic_results' => $organic,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => $e->getMessage()];
        }
    }
}
