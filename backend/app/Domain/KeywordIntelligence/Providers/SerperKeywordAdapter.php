<?php

namespace App\Domain\KeywordIntelligence\Providers;

use App\Domain\Competitors\Providers\SerperProvider;
use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\KeywordIntelligence\Contracts\KeywordIntelligenceProviderInterface;
use App\Domain\RankChecker\SerpCreditService;
use App\Models\Website;

/**
 * Serper.dev Google SERP Intelligence adapter.
 * Reuses the platform's multi-key failover pool, BYOK keys, and cross-user Global SERP Cache.
 * Extracts People Also Ask questions, Related Searches, SERP features, and Top-10 ranking URLs.
 * Never invents search volume, KD, CPC, or paid competition.
 */
class SerperKeywordAdapter implements KeywordIntelligenceProviderInterface
{
    public function __construct(
        private SerperProvider $serperProvider,
        private SerpCreditService $serpCreditService
    ) {
    }

    public function getName(): string
    {
        return 'serper';
    }

    public function getLabel(): string
    {
        return 'Serper.dev (Live Google SERP, PAA & Related)';
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        return $this->serperProvider->isConfigured($organizationId);
    }

    public function supportedCapabilities(): array
    {
        return ['serp', 'questions', 'search_related', 'serp_clustering', 'serp_features'];
    }

    public function fetchKeywordIdeas(
        string $seed,
        string $country = 'US',
        string $language = 'en',
        array $options = []
    ): array {
        $organizationId = isset($options['organization_id']) ? (int) $options['organization_id'] : null;
        $website = ($options['website'] ?? null) instanceof Website ? $options['website'] : null;
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : null;
        $device = (string) ($options['device'] ?? 'desktop');
        $location = $options['location'] ?? null;

        $serp = $this->fetchSerpData($seed, $country, $language, $device, $location, $organizationId, $website, $userId);
        if (!($serp['available'] ?? false)) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'keywords' => [],
                'error' => $serp['error'] ?? 'Serper SERP request failed.',
            ];
        }

        $topUrls = array_slice(array_map(fn ($r) => [
            'position' => (int) ($r['position'] ?? 0),
            'domain' => (string) ($r['domain'] ?? ''),
            'url' => (string) ($r['url'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'snippet' => (string) ($r['snippet'] ?? ''),
        ], $serp['organic_results'] ?? []), 0, 10);

        $features = $serp['serp_features'] ?? [];
        $keywords = [];

        // Enrich the seed keyword itself with live SERP features & Top-10 URLs
        $keywords[] = [
            'keyword' => trim($seed),
            'discovery_source' => 'serp_seed',
            'data_source' => $this->getName(),
            'metric_classification' => 'third_party_estimated',
            'search_volume' => null,
            'keyword_difficulty' => null,
            'cpc' => null,
            'paid_competition' => null,
            'competition_level' => null,
            'search_intent' => null,
            'intent_source' => null,
            'trend_data' => null,
            'trend_direction' => 'unavailable',
            'serp_features' => $features,
            'serp_top_urls' => $topUrls,
            'target_position' => $serp['target_position'] ?? null,
            'ranking_url' => $serp['ranking_url'] ?? null,
        ];

        // Extract People Also Ask questions
        foreach ($serp['people_also_ask'] ?? [] as $paa) {
            $q = trim((string) ($paa['question'] ?? ''));
            if ($q === '') {
                continue;
            }
            $keywords[] = [
                'keyword' => $q,
                'discovery_source' => 'questions',
                'data_source' => $this->getName(),
                'metric_classification' => 'third_party_estimated',
                'search_volume' => null,
                'keyword_difficulty' => null,
                'cpc' => null,
                'paid_competition' => null,
                'competition_level' => null,
                'search_intent' => null,
                'intent_source' => null,
                'trend_data' => null,
                'trend_direction' => 'unavailable',
                'serp_features' => ['people_also_ask'],
                'serp_top_urls' => null,
            ];
        }

        // Extract Google Related Searches
        foreach ($serp['related_searches'] ?? [] as $rel) {
            $q = trim((string) $rel);
            if ($q === '') {
                continue;
            }
            $keywords[] = [
                'keyword' => $q,
                'discovery_source' => 'search_related',
                'data_source' => $this->getName(),
                'metric_classification' => 'third_party_estimated',
                'search_volume' => null,
                'keyword_difficulty' => null,
                'cpc' => null,
                'paid_competition' => null,
                'competition_level' => null,
                'search_intent' => null,
                'intent_source' => null,
                'trend_data' => null,
                'trend_direction' => 'unavailable',
                'serp_features' => null,
                'serp_top_urls' => null,
            ];
        }

        return [
            'available' => true,
            'provider' => $this->getName(),
            'keywords' => $keywords,
            'serp' => $serp,
            'credits_used' => (int) ($serp['credits_used'] ?? 0),
            'cost_usd' => (float) ($serp['cost_usd'] ?? 0.0),
            'cache_hit' => (bool) ($serp['from_cache'] ?? false),
        ];
    }

    /**
     * Fetch live Google SERP for a keyword, leveraging the cross-user Global SERP Cache first.
     */
    public function fetchSerpData(
        string $keyword,
        string $country = 'US',
        string $language = 'en',
        string $device = 'desktop',
        ?string $location = null,
        ?int $organizationId = null,
        ?Website $website = null,
        ?int $userId = null,
        bool $forceRefresh = false
    ): array {
        $country = strtoupper(trim($country)) ?: 'US';
        $language = strtolower(trim($language)) ?: 'en';
        $device = strtolower(trim($device)) === 'mobile' ? 'mobile' : 'desktop';
        $effectiveLocation = trim((string) ($location ?? '')) ?: CountryCatalog::name($country);
        $targetDomain = $website ? preg_replace('/^www\./i', '', strtolower(trim((string) $website->domain))) : null;

        if (!$forceRefresh) {
            $cached = $this->serpCreditService->lookupGlobalCache(
                $keyword,
                $country,
                $language,
                $device,
                $effectiveLocation,
                10,
                $targetDomain
            );
            if ($cached) {
                $decoded = $cached->getDecodedPayload();
                if (!empty($decoded['available'])) {
                    $decoded['from_cache'] = true;
                    $decoded['credits_used'] = 0;
                    $decoded['cost_usd'] = 0.0;
                    return $this->annotateTargetPosition($decoded, $targetDomain);
                }
            }
        }

        if (!$this->serperProvider->isConfigured($organizationId)) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'error' => 'Serper.dev API key is not configured.',
            ];
        }

        // Respect workspace quota if org present
        $org = $website?->organization;
        if ($org) {
            $quota = $this->serpCreditService->getQuotaStatus($org);
            if ($quota['exceeded']) {
                return [
                    'available' => false,
                    'provider' => $this->getName(),
                    'error' => 'Workspace SERP credit quota reached for live SERP lookups.',
                ];
            }
        }

        $res = $this->serperProvider->fetchSerp($keyword, $country, $language, $device, $effectiveLocation, 10, null);
        if (!($res['available'] ?? false)) {
            return $res;
        }

        $pages = max(1, (int) ($res['pages_fetched'] ?? 1));
        $this->serpCreditService->storeGlobalCache(
            'serper',
            $keyword,
            $country,
            $language,
            $device,
            $effectiveLocation,
            10,
            $res,
            $pages
        );

        if ($website) {
            $this->serpCreditService->recordUsage(
                $website,
                $userId,
                isset($res['platform_api_key_id']) ? (int) $res['platform_api_key_id'] : null,
                'serper',
                $keyword,
                $country,
                10,
                $pages,
                false,
                (string) ($res['key_source'] ?? 'platform_pool')
            );
        }

        $res['from_cache'] = false;
        $res['credits_used'] = $pages;
        return $this->annotateTargetPosition($res, $targetDomain);
    }

    private function annotateTargetPosition(array $serp, ?string $targetDomain): array
    {
        $pos = null;
        $url = null;
        if ($targetDomain) {
            foreach ($serp['organic_results'] ?? [] as $row) {
                $d = preg_replace('/^www\./i', '', strtolower((string) ($row['domain'] ?? '')));
                if ($d !== '' && ($d === $targetDomain || str_ends_with($d, '.' . $targetDomain))) {
                    $pos = (int) ($row['position'] ?? 0) ?: null;
                    $url = (string) ($row['url'] ?? '') ?: null;
                    break;
                }
            }
        }
        $serp['target_position'] = $pos;
        $serp['ranking_url'] = $url;
        return $serp;
    }
}
