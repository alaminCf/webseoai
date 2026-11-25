<?php

namespace App\Domain\KeywordIntelligence\Providers;

use App\Domain\KeywordIntelligence\Contracts\KeywordIntelligenceProviderInterface;
use App\Models\KiProviderUsageLog;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Central provider coordinator for Keyword Intelligence.
 * Reports exact provider availability, caches provider calls, isolates provider failures,
 * and logs usage & cost metrics into `ki_provider_usage_logs`.
 */
class KeywordProviderManager
{
    public function __construct(
        private GoogleAutocompleteAdapter $autocomplete,
        private SerperKeywordAdapter $serper,
        private DataForSeoKeywordAdapter $dataForSeo,
        private SemrushKeywordAdapter $semrush
    ) {
    }

    public function getAutocomplete(): GoogleAutocompleteAdapter
    {
        return $this->autocomplete;
    }

    public function getSerper(): SerperKeywordAdapter
    {
        return $this->serper;
    }

    public function getDataForSeo(): DataForSeoKeywordAdapter
    {
        return $this->dataForSeo;
    }

    public function getSemrush(): SemrushKeywordAdapter
    {
        return $this->semrush;
    }

    /**
     * Report honest provider configuration & capability status for a website/workspace.
     */
    public function getProviderStatus(Website $website): array
    {
        $orgId = $website->organization_id;
        $gscConnected = WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', WebsiteIntegration::PROVIDER_GSC)
            ->where('status', WebsiteIntegration::STATUS_CONNECTED)
            ->exists();

        $serperConfigured = $this->serper->isConfigured($orgId);
        $dfsConfigured = $this->dataForSeo->isConfigured($orgId);
        $semrushConfigured = $this->semrush->isConfigured($orgId);

        $hasVolumeProvider = $dfsConfigured || $semrushConfigured;

        return [
            'has_volume_provider' => $hasVolumeProvider,
            'has_serp_provider' => $serperConfigured || $dfsConfigured,
            'has_gsc' => $gscConnected,
            'providers' => [
                [
                    'name' => 'gsc',
                    'label' => 'Google Search Console (First-Party)',
                    'configured' => $gscConnected,
                    'classification' => 'first_party_gsc',
                    'capabilities' => ['gsc_queries', 'clicks', 'impressions', 'ctr', 'avg_position', 'cannibalization'],
                ],
                [
                    'name' => $this->autocomplete->getName(),
                    'label' => $this->autocomplete->getLabel(),
                    'configured' => true,
                    'classification' => 'third_party_estimated',
                    'capabilities' => $this->autocomplete->supportedCapabilities(),
                ],
                [
                    'name' => $this->serper->getName(),
                    'label' => $this->serper->getLabel(),
                    'configured' => $serperConfigured,
                    'classification' => 'third_party_estimated',
                    'capabilities' => $this->serper->supportedCapabilities(),
                ],
                [
                    'name' => $this->dataForSeo->getName(),
                    'label' => $this->dataForSeo->getLabel(),
                    'configured' => $dfsConfigured,
                    'classification' => 'third_party_estimated',
                    'capabilities' => $this->dataForSeo->supportedCapabilities(),
                ],
                [
                    'name' => $this->semrush->getName(),
                    'label' => $this->semrush->getLabel(),
                    'configured' => $semrushConfigured,
                    'classification' => 'third_party_estimated',
                    'capabilities' => $this->semrush->supportedCapabilities(),
                ],
            ],
            'data_notice' => $hasVolumeProvider
                ? 'Third-party Search Volume, Keyword Difficulty, CPC & Trend provider is active.'
                : 'No third-party Search Volume / KD / CPC provider (DataForSEO v3 or Semrush) is connected. Live Google Autocomplete, Serper SERP/PAA, Competitor Gaps & First-Party GSC data are active; unavailable metrics display "Data Unavailable" and are never fabricated.',
        ];
    }

    /**
     * Execute a provider keyword idea lookup with caching, failure isolation, and telemetry logging.
     */
    public function executeDiscoveryCall(
        KeywordIntelligenceProviderInterface $provider,
        Website $website,
        string $seed,
        string $country,
        string $language,
        array $options = [],
        ?int $userId = null
    ): array {
        $start = microtime(true);
        $cacheKey = 'ki_disc:' . md5($provider->getName() . '|' . strtolower(trim($seed)) . '|' . strtoupper($country) . '|' . strtolower($language) . '|' . json_encode($options['sources'] ?? []));

        if (empty($options['bypass_cache'])) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached['available'])) {
                $this->logUsage($website, $userId, $provider->getName(), 'keyword_discovery', $seed, count($cached['keywords'] ?? []), 0, 0.0, true, 'success', 1);
                return $cached;
            }
        }

        try {
            $res = $provider->fetchKeywordIdeas($seed, $country, $language, array_merge($options, [
                'organization_id' => $website->organization_id,
                'website' => $website,
                'user_id' => $userId,
            ]));

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $available = (bool) ($res['available'] ?? false);

            if ($available) {
                Cache::put($cacheKey, $res, now()->addHours(6));
            }

            $this->logUsage(
                $website,
                $userId,
                $provider->getName(),
                'keyword_discovery',
                $seed,
                count($res['keywords'] ?? []),
                (int) ($res['credits_used'] ?? 0),
                (float) ($res['cost_usd'] ?? 0.0),
                (bool) ($res['cache_hit'] ?? false),
                $available ? 'success' : 'failed',
                $durationMs,
                $available ? null : ($res['error'] ?? 'Provider unavailable')
            );

            return $res;
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->logUsage($website, $userId, $provider->getName(), 'keyword_discovery', $seed, 0, 0, 0.0, false, 'failed', $durationMs, $e->getMessage());

            return [
                'available' => false,
                'provider' => $provider->getName(),
                'keywords' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function logUsage(
        Website $website,
        ?int $userId,
        string $provider,
        string $operation,
        string $querySummary,
        int $resultsCount,
        int $creditsUsed,
        float $costUsd,
        bool $cacheHit,
        string $status,
        int $durationMs,
        ?string $error = null
    ): void {
        try {
            KiProviderUsageLog::create([
                'organization_id' => $website->organization_id,
                'website_id' => $website->id,
                'user_id' => $userId,
                'provider' => $provider,
                'operation' => $operation,
                'query_summary' => mb_substr($querySummary, 0, 250),
                'results_count' => $resultsCount,
                'credits_used' => $creditsUsed,
                'estimated_cost_usd' => $costUsd,
                'cache_hit' => $cacheHit,
                'status' => $status,
                'duration_ms' => $durationMs,
                'error_message' => $error ? mb_substr($error, 0, 1000) : null,
            ]);
        } catch (Throwable) {
            // Telemetry logging must never interrupt discovery
        }
    }
}
