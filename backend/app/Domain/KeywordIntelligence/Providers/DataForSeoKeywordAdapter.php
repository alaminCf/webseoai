<?php

namespace App\Domain\KeywordIntelligence\Providers;

use App\Domain\Competitors\Providers\DataForSeoProvider;
use App\Domain\KeywordIntelligence\Contracts\KeywordIntelligenceProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO v3 Labs Keyword Intelligence adapter.
 * Reuses DataForSeoProvider::resolveCredentials() (server env OR Super Admin encrypted credentials).
 * Supplies genuine provider metrics: Search Volume, Keyword Difficulty, CPC, Paid Competition,
 * Search Intent, and 12-month historical search volume trend data.
 */
class DataForSeoKeywordAdapter implements KeywordIntelligenceProviderInterface
{
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('competitors.providers.dataforseo.base_url', 'https://api.dataforseo.com/v3'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 18);
    }

    public function getName(): string
    {
        return 'dataforseo';
    }

    public function getLabel(): string
    {
        return 'DataForSEO v3 (Volume, KD, CPC, Intent & Trends)';
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        $creds = DataForSeoProvider::resolveCredentials();
        return $creds['login'] !== '' && $creds['password'] !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['suggestions', 'related', 'volume', 'difficulty', 'cpc', 'competition', 'intent', 'trends', 'domain_keywords'];
    }

    public function fetchKeywordIdeas(
        string $seed,
        string $country = 'US',
        string $language = 'en',
        array $options = []
    ): array {
        $creds = DataForSeoProvider::resolveCredentials();
        if ($creds['login'] === '' || $creds['password'] === '') {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'keywords' => [],
                'error' => 'DataForSEO v3 credentials are not configured.',
            ];
        }

        $limit = max(10, min(100, (int) ($options['limit'] ?? 50)));
        $locationCode = (int) (config('competitors.country_map.' . strtoupper($country) . '.location_code') ?? 2840);
        $langCode = strtolower(trim($language)) ?: 'en';
        $sources = $options['sources'] ?? ['suggestions', 'related'];

        $allKeywords = [];
        $seen = [];
        $totalCost = 0.0;
        $lastError = null;
        $anySuccess = false;

        $endpoints = [];
        if (in_array('suggestions', $sources, true) || in_array('long_tail', $sources, true) || in_array('questions', $sources, true)) {
            $endpoints[] = ['path' => '/dataforseo_labs/google/keyword_suggestions/live', 'source' => 'suggestions'];
        }
        if (in_array('related', $sources, true)) {
            $endpoints[] = ['path' => '/dataforseo_labs/google/related_keywords/live', 'source' => 'related'];
        }
        if (empty($endpoints)) {
            $endpoints[] = ['path' => '/dataforseo_labs/google/keyword_suggestions/live', 'source' => 'suggestions'];
        }

        foreach ($endpoints as $ep) {
            try {
                $res = Http::withBasicAuth($creds['login'], $creds['password'])
                    ->acceptJson()
                    ->timeout($this->timeout)
                    ->post($this->baseUrl . $ep['path'], [[
                        'keyword' => trim($seed),
                        'location_code' => $locationCode,
                        'language_code' => $langCode,
                        'include_seed_keyword' => true,
                        'limit' => $limit,
                    ]]);

                if (!$res->successful()) {
                    $lastError = "DataForSEO HTTP {$res->status()}";
                    continue;
                }

                $task = $res->json('tasks.0') ?? [];
                if ((int) ($task['status_code'] ?? 0) !== 20000) {
                    $lastError = (string) ($task['status_message'] ?? 'DataForSEO task failed.');
                    continue;
                }

                $anySuccess = true;
                $totalCost += (float) ($task['cost'] ?? 0.01);
                $items = $task['result'][0]['items'] ?? [];

                foreach ($items as $item) {
                    // related_keywords nests inside keyword_data, keyword_suggestions puts fields directly
                    $kwData = is_array($item['keyword_data'] ?? null) ? $item['keyword_data'] : $item;
                    $parsed = $this->mapDataForSeoItem($kwData, $ep['source']);
                    if (!$parsed) {
                        continue;
                    }
                    $norm = strtolower(preg_replace('/\s+/', ' ', $parsed['keyword']));
                    if (!isset($seen[$norm])) {
                        $seen[$norm] = true;
                        $allKeywords[] = $parsed;
                    }
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if (!$anySuccess) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'keywords' => [],
                'error' => $lastError ?: 'DataForSEO request failed.',
            ];
        }

        return [
            'available' => true,
            'provider' => $this->getName(),
            'keywords' => $allKeywords,
            'credits_used' => count($endpoints),
            'cost_usd' => round($totalCost, 5),
        ];
    }

    /**
     * Discover ranked keywords for a given website URL / domain using DataForSEO Labs.
     */
    public function fetchDomainKeywordIdeas(
        string $domain,
        string $country = 'US',
        string $language = 'en',
        int $limit = 50
    ): array {
        $creds = DataForSeoProvider::resolveCredentials();
        if ($creds['login'] === '' || $creds['password'] === '') {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'DataForSEO is not configured.'];
        }

        $cleanDomain = preg_replace('/^www\./i', '', strtolower((string) (parse_url(str_contains($domain, '://') ? $domain : "https://{$domain}", PHP_URL_HOST) ?: $domain)));
        $locationCode = (int) (config('competitors.country_map.' . strtoupper($country) . '.location_code') ?? 2840);

        try {
            $res = Http::withBasicAuth($creds['login'], $creds['password'])
                ->acceptJson()
                ->timeout($this->timeout)
                ->post($this->baseUrl . '/dataforseo_labs/google/ranked_keywords/live', [[
                    'target' => $cleanDomain,
                    'location_code' => $locationCode,
                    'language_code' => strtolower(trim($language)) ?: 'en',
                    'limit' => max(10, min(100, $limit)),
                ]]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => "DataForSEO HTTP {$res->status()}"];
            }

            $task = $res->json('tasks.0') ?? [];
            $items = $task['result'][0]['items'] ?? [];
            $keywords = [];

            foreach ($items as $item) {
                $kwData = $item['keyword_data'] ?? [];
                $parsed = $this->mapDataForSeoItem($kwData, 'competitor_keywords');
                if ($parsed) {
                    $serpEl = $item['ranked_serp_element']['serp_item'] ?? [];
                    $parsed['serp_features'] = $item['ranked_serp_element']['serp_item_types'] ?? null;
                    $parsed['competitor_position'] = isset($serpEl['rank_absolute']) ? (int) $serpEl['rank_absolute'] : null;
                    $keywords[] = $parsed;
                }
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'keywords' => $keywords,
                'credits_used' => 1,
                'cost_usd' => round((float) ($task['cost'] ?? 0.015), 5),
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => $e->getMessage()];
        }
    }

    private function mapDataForSeoItem(array $kwData, string $discoverySource): ?array
    {
        $keyword = trim((string) ($kwData['keyword'] ?? ''));
        if ($keyword === '') {
            return null;
        }

        $info = is_array($kwData['keyword_info'] ?? null) ? $kwData['keyword_info'] : [];
        $props = is_array($kwData['keyword_properties'] ?? null) ? $kwData['keyword_properties'] : [];
        $intentInfo = is_array($kwData['search_intent_info'] ?? null) ? $kwData['search_intent_info'] : [];
        $serpInfo = is_array($kwData['serp_info'] ?? null) ? $kwData['serp_info'] : [];

        $monthlyRaw = is_array($info['monthly_searches'] ?? null) ? $info['monthly_searches'] : [];
        $trendData = null;
        $trendDirection = 'unavailable';

        if (!empty($monthlyRaw)) {
            $points = [];
            foreach ($monthlyRaw as $m) {
                if (!isset($m['year'], $m['month'])) {
                    continue;
                }
                $points[] = [
                    'period' => sprintf('%04d-%02d', (int) $m['year'], (int) $m['month']),
                    'volume' => isset($m['search_volume']) ? (int) $m['search_volume'] : 0,
                    'type' => 'historical',
                ];
            }
            usort($points, fn ($a, $b) => strcmp($a['period'], $b['period']));
            if (count($points) >= 3) {
                $trendData = $points;
                $trendDirection = $this->analyzeTrendDirection($points);
            }
        }

        $paidComp = isset($info['competition']) && is_numeric($info['competition'])
            ? round((float) $info['competition'], 4)
            : null;
        $compLevel = isset($info['competition_level']) && is_string($info['competition_level'])
            ? strtolower($info['competition_level'])
            : ($paidComp !== null ? ($paidComp >= 0.67 ? 'high' : ($paidComp >= 0.34 ? 'medium' : 'low')) : null);

        $rawIntent = strtolower(trim((string) ($intentInfo['main_intent'] ?? '')));
        $validIntents = ['informational', 'navigational', 'commercial', 'transactional'];
        $providerIntent = in_array($rawIntent, $validIntents, true) ? $rawIntent : null;

        return [
            'keyword' => $keyword,
            'discovery_source' => $discoverySource,
            'data_source' => $this->getName(),
            'metric_classification' => 'third_party_estimated',
            'search_volume' => isset($info['search_volume']) && is_numeric($info['search_volume']) ? (int) $info['search_volume'] : null,
            'keyword_difficulty' => isset($props['keyword_difficulty']) && is_numeric($props['keyword_difficulty']) ? round((float) $props['keyword_difficulty'], 2) : null,
            'cpc' => isset($info['cpc']) && is_numeric($info['cpc']) ? round((float) $info['cpc'], 2) : null,
            'paid_competition' => $paidComp,
            'competition_level' => $compLevel,
            'search_intent' => $providerIntent,
            'intent_source' => $providerIntent ? 'provider' : null,
            'intent_confidence' => $providerIntent ? 0.92 : null,
            'trend_data' => $trendData,
            'trend_direction' => $trendDirection,
            'serp_features' => !empty($serpInfo['serp_item_types']) && is_array($serpInfo['serp_item_types']) ? $serpInfo['serp_item_types'] : null,
            'serp_top_urls' => null,
        ];
    }

    private function analyzeTrendDirection(array $points): string
    {
        $vols = array_column($points, 'volume');
        $count = count($vols);
        if ($count < 4) {
            return 'stable';
        }
        $avg = array_sum($vols) / max(1, $count);
        if ($avg <= 0) {
            return 'stable';
        }
        $max = max($vols);
        $min = min($vols);
        if (($max - $min) / $avg >= 1.1 && $count >= 8) {
            return 'seasonal';
        }

        $half = (int) floor($count / 2);
        $firstAvg = array_sum(array_slice($vols, 0, $half)) / max(1, $half);
        $secondAvg = array_sum(array_slice($vols, $half)) / max(1, $count - $half);
        $changePct = $firstAvg > 0 ? (($secondAvg - $firstAvg) / $firstAvg) * 100 : 0;

        if ($changePct >= 15) {
            return 'rising';
        }
        if ($changePct <= -15) {
            return 'declining';
        }
        return 'stable';
    }
}
