<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

class SerpApiProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = trim((string) config('competitors.providers.serpapi.api_key', ''));
        $this->baseUrl = rtrim((string) config('competitors.providers.serpapi.base_url', 'https://serpapi.com'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    public function getName(): string
    {
        return 'serpapi';
    }

    public function getLabel(): string
    {
        return 'SerpApi (Google Organic SERP)';
    }

    public function isConfigured(): bool
    {
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
            return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => 'SERPAPI_KEY is not configured.'];
        }

        $seedKeywords = array_slice(array_values(array_filter($context['seed_keywords'] ?? [])), 0, 3);
        if (empty($seedKeywords)) {
            $seedKeywords = [$context['industry'] ?? str_replace(['-', '.'], ' ', pathinfo($domain, PATHINFO_FILENAME))];
        }

        $domainStats = [];
        foreach ($seedKeywords as $kw) {
            $serp = $this->fetchSerp((string) $kw, $country, $language, 'desktop');
            if (!$serp['available']) {
                continue;
            }
            foreach ($serp['organic_results'] as $row) {
                $d = preg_replace('/^www\./i', '', strtolower((string) ($row['domain'] ?? '')));
                if ($d === '' || $d === strtolower($domain)) {
                    continue;
                }
                if (!isset($domainStats[$d])) {
                    $domainStats[$d] = ['domain' => $d, 'shared_keywords' => 0, 'positions' => [], 'sample_urls' => [], 'source' => 'serpapi'];
                }
                $domainStats[$d]['shared_keywords']++;
                $domainStats[$d]['positions'][] = $row['position'];
                if (count($domainStats[$d]['sample_urls']) < 3 && !empty($row['url'])) {
                    $domainStats[$d]['sample_urls'][] = $row['url'];
                }
            }
        }

        $candidates = [];
        $totalSeeds = max(1, count($seedKeywords));
        foreach ($domainStats as $stat) {
            $candidates[] = [
                'domain' => $stat['domain'],
                'shared_keywords' => $stat['shared_keywords'],
                'avg_position' => round(array_sum($stat['positions']) / max(1, count($stat['positions'])), 1),
                'serp_overlap_pct' => round(($stat['shared_keywords'] / $totalSeeds) * 100, 1),
                'sample_urls' => $stat['sample_urls'],
                'source' => 'serpapi',
            ];
        }

        usort($candidates, fn ($a, $b) => $b['shared_keywords'] <=> $a['shared_keywords']);

        return [
            'available' => true,
            'provider' => $this->getName(),
            'methodology' => 'SerpApi multi-query Google Organic SERP overlap across ' . count($seedKeywords) . " seed keyword(s) ({$country}/{$language}).",
            'candidates' => array_slice($candidates, 0, 15),
        ];
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => 'SerpApi provides SERP snapshots, not domain-level traffic or backlink metrics.'];
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'SerpApi does not provide domain-wide keyword lists.'];
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => 'SerpApi does not provide backlink datasets.'];
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'SERPAPI_KEY is not configured.'];
        }

        try {
            $gl = (string) (config('competitors.country_map.' . strtoupper($country) . '.gl') ?? 'us');
            $params = [
                'engine' => 'google',
                'q' => $keyword,
                'gl' => $gl,
                'hl' => strtolower($language),
                'device' => strtolower($device),
                'num' => 20,
                'api_key' => $this->apiKey,
            ];
            if ($location) {
                $params['location'] = $location;
            }

            $res = Http::acceptJson()->timeout($this->timeout)->get("{$this->baseUrl}/search.json", $params);
            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'http_status' => $res->status(), 'error' => 'SerpApi request failed (HTTP ' . $res->status() . ')'];
            }

            $json = $res->json() ?? [];
            $features = [];
            foreach (['answer_box' => 'featured_snippet', 'related_questions' => 'people_also_ask', 'local_results' => 'local_pack', 'knowledge_graph' => 'knowledge_panel', 'shopping_results' => 'shopping', 'inline_videos' => 'video_carousel', 'top_stories' => 'top_stories'] as $key => $label) {
                if (!empty($json[$key])) {
                    $features[] = $label;
                }
            }

            $organic = [];
            foreach ($json['organic_results'] ?? [] as $item) {
                $url = (string) ($item['link'] ?? '');
                $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
                $organic[] = [
                    'position' => (int) ($item['position'] ?? (count($organic) + 1)),
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
                'cost_usd' => 0.01,
                'serp_features' => $features,
                'organic_results' => $organic,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => $e->getMessage()];
        }
    }
}
