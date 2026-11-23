<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Domain\Competitors\Support\CountryCatalog;
use App\Models\WebsiteIntegration;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Serper.dev Google Search API adapter (2,500 free queries on signup).
 * Covers live Google Organic SERP snapshots and multi-query competitor discovery.
 */
class SerperProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private ?string $runtimeApiKey = null;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey = self::resolveEnvApiKey();
        $this->baseUrl = rtrim((string) config('competitors.providers.serper.base_url', 'https://google.serper.dev'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    public function setRuntimeApiKey(?string $apiKey): self
    {
        $clean = trim((string) ($apiKey ?? ''));
        $this->runtimeApiKey = $clean !== '' ? $clean : null;
        return $this;
    }

    public static function resolveEnvApiKey(): string
    {
        $fromConfig = trim((string) config('competitors.providers.serper.api_key', ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        foreach (['SERPER_API_KEY', 'SERPER_KEY', 'SERPER_API', 'SERPER_TOKEN', 'NEXT_PUBLIC_SERPER_API_KEY'] as $var) {
            $val = $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var);
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }

        return '';
    }

    public static function resolveWorkspaceIntegration(?int $organizationId): ?WebsiteIntegration
    {
        if (!$organizationId) {
            return null;
        }

        try {
            return WebsiteIntegration::where('organization_id', $organizationId)
                ->where('provider', WebsiteIntegration::PROVIDER_SERPER)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->orderByDesc('updated_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolveWorkspaceApiKey(?int $organizationId): string
    {
        $integration = self::resolveWorkspaceIntegration($organizationId);
        if (!$integration) {
            return '';
        }

        $creds = $integration->getDecryptedCredentials();
        if (is_array($creds)) {
            return trim((string) ($creds['api_key'] ?? ''));
        }

        return is_string($creds) ? trim($creds) : '';
    }

    public function resolveEffectiveApiKey(?int $organizationId = null): string
    {
        if ($this->runtimeApiKey !== null && $this->runtimeApiKey !== '') {
            return $this->runtimeApiKey;
        }

        $envKey = self::resolveEnvApiKey();
        if ($envKey !== '') {
            return $envKey;
        }

        return self::resolveWorkspaceApiKey($organizationId);
    }

    public function getName(): string
    {
        return 'serper';
    }

    public function getLabel(): string
    {
        return 'Serper.dev (Google Search API)';
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        $this->apiKey = $this->resolveEffectiveApiKey($organizationId);
        return $this->apiKey !== '';
    }

    /**
     * Live-verify a Serper.dev API key before saving it encrypted in the workspace.
     */
    public function verifyApiKey(string $apiKey): array
    {
        $cleanKey = trim($apiKey);
        if ($cleanKey === '') {
            return ['valid' => false, 'error' => 'API key cannot be empty.'];
        }

        try {
            $res = Http::withHeaders([
                'X-API-KEY' => $cleanKey,
                'Content-Type' => 'application/json',
            ])->timeout(12)->post("{$this->baseUrl}/search", [
                'q' => 'google',
                'gl' => 'us',
                'hl' => 'en',
                'num' => 1,
            ]);

            if ($res->successful()) {
                $json = $res->json() ?? [];
                return [
                    'valid' => true,
                    'credits' => $json['credits'] ?? null,
                ];
            }

            $bodyMsg = trim((string) ($res->json('message') ?? $res->json('error') ?? ''));
            return [
                'valid' => false,
                'http_status' => $res->status(),
                'error' => $bodyMsg !== ''
                    ? "Serper.dev rejected this key (HTTP {$res->status()}): {$bodyMsg}"
                    : "Serper.dev rejected this key (HTTP {$res->status()}). Please check your API key at serper.dev/api-key.",
            ];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'error' => 'Could not reach Serper.dev to verify key: ' . $e->getMessage(),
            ];
        }
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

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null, int $depth = 20): array
    {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'SERPER_API_KEY is not configured.'];
        }

        try {
            $gl = CountryCatalog::gl($country);
            $geoLocation = trim((string) ($location ?? '')) ?: CountryCatalog::serperLocation($country);
            $num = max(10, min(100, $depth));
            $payload = [
                'q' => $keyword,
                'gl' => $gl,
                'hl' => strtolower($language),
                'num' => $num,
            ];
            if ($geoLocation !== '') {
                $payload['location'] = $geoLocation;
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
                $pos = (int) ($item['position'] ?? ($idx + 1));
                $organic[] = [
                    'position' => $pos,
                    'google_page' => (int) ceil($pos / 10),
                    'rank_on_page' => (($pos - 1) % 10) + 1,
                    'domain' => $host,
                    'url' => $url,
                    'title' => (string) ($item['title'] ?? ''),
                    'snippet' => (string) ($item['snippet'] ?? ''),
                    'date' => $item['date'] ?? null,
                    'sitelinks' => array_slice(is_array($item['sitelinks'] ?? null) ? $item['sitelinks'] : [], 0, 4),
                ];
            }

            $places = [];
            foreach ($json['places'] ?? [] as $pIdx => $pl) {
                $places[] = [
                    'position' => (int) ($pl['position'] ?? ($pIdx + 1)),
                    'title' => (string) ($pl['title'] ?? ''),
                    'address' => (string) ($pl['address'] ?? ''),
                    'rating' => $pl['rating'] ?? null,
                    'rating_count' => $pl['ratingCount'] ?? null,
                    'category' => $pl['category'] ?? null,
                    'website' => $pl['website'] ?? null,
                ];
            }

            $paa = [];
            foreach ($json['peopleAlsoAsk'] ?? [] as $q) {
                $paa[] = [
                    'question' => (string) ($q['question'] ?? ''),
                    'snippet' => (string) ($q['snippet'] ?? ''),
                    'title' => (string) ($q['title'] ?? ''),
                    'link' => (string) ($q['link'] ?? ''),
                ];
            }

            $related = [];
            foreach ($json['relatedSearches'] ?? [] as $r) {
                if (!empty($r['query'])) {
                    $related[] = (string) $r['query'];
                }
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'cost_usd' => $num > 10 ? 0.002 : 0.001,
                'serp_features' => $features,
                'organic_results' => $organic,
                'local_pack' => $places,
                'people_also_ask' => $paa,
                'related_searches' => $related,
                'answer_box' => $json['answerBox'] ?? null,
                'search_parameters' => [
                    'q' => $keyword,
                    'gl' => $gl,
                    'hl' => strtolower($language),
                    'location' => $geoLocation,
                    'num' => $num,
                ],
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => $e->getMessage()];
        }
    }
}
