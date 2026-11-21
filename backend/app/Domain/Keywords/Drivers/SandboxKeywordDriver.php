<?php

namespace App\Domain\Keywords\Drivers;

use App\Domain\Keywords\Contracts\KeywordProviderInterface;

class SandboxKeywordDriver implements KeywordProviderInterface
{
    private string $providerName = 'sandbox';

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getKeywordSuggestions(string $query, array $options = []): array
    {
        $country = strtoupper($options['country'] ?? 'US');
        $language = strtolower($options['language'] ?? 'en');
        $limit = (int) ($options['limit'] ?? 20);

        $seed = strtolower(trim($query));
        $generated = $this->generateKeywordsForSeed($seed, $country, $language);

        return array_slice($generated, 0, $limit);
    }

    public function getKeywordMetrics(array $keywords, array $options = []): array
    {
        $country = strtoupper($options['country'] ?? 'US');
        $language = strtolower($options['language'] ?? 'en');

        $results = [];
        foreach ($keywords as $kw) {
            $kwNormalized = strtolower(trim($kw));
            $results[$kwNormalized] = $this->buildMetricPayload($kwNormalized, $country, $language);
        }

        return $results;
    }

    public function getRelatedClusters(string $keyword, array $options = []): array
    {
        $seed = strtolower(trim($keyword));
        $country = strtoupper($options['country'] ?? 'US');
        $language = strtolower($options['language'] ?? 'en');

        return [
            [
                'cluster_name' => "Core & Strategy ({$seed})",
                'intent' => 'informational',
                'keywords' => [
                    "best {$seed} strategies",
                    "how to improve {$seed}",
                    "{$seed} checklist 2026",
                    "{$seed} best practices",
                ],
            ],
            [
                'cluster_name' => "Technical & Audit ({$seed})",
                'intent' => 'commercial',
                'keywords' => [
                    "technical {$seed} audit",
                    "{$seed} tools for agencies",
                    "automate {$seed} monitoring",
                ],
            ],
            [
                'cluster_name' => "Commercial & Services ({$seed})",
                'intent' => 'transactional',
                'keywords' => [
                    "hire {$seed} expert",
                    "{$seed} agency pricing",
                    "{$seed} automation software",
                ],
            ],
        ];
    }

    /**
     * Generate structured suggestion list with realistic metric provenance.
     */
    private function generateKeywordsForSeed(string $seed, string $country, string $language): array
    {
        $modifiers = [
            ['prefix' => 'best', 'suffix' => 'tools', 'intent' => 'commercial', 'mult_vol' => 2.4, 'diff' => 54],
            ['prefix' => 'how to optimize', 'suffix' => '', 'intent' => 'informational', 'mult_vol' => 1.8, 'diff' => 38],
            ['prefix' => '', 'suffix' => 'checklist', 'intent' => 'informational', 'mult_vol' => 1.5, 'diff' => 32],
            ['prefix' => '', 'suffix' => 'audit guide', 'intent' => 'informational', 'mult_vol' => 2.1, 'diff' => 45],
            ['prefix' => 'top', 'suffix' => 'software', 'intent' => 'commercial', 'mult_vol' => 3.2, 'diff' => 68],
            ['prefix' => 'affordable', 'suffix' => 'agency', 'intent' => 'transactional', 'mult_vol' => 0.9, 'diff' => 42],
            ['prefix' => '', 'suffix' => 'for ecommerce', 'intent' => 'commercial', 'mult_vol' => 2.8, 'diff' => 59],
            ['prefix' => '', 'suffix' => 'for shopify', 'intent' => 'commercial', 'mult_vol' => 2.5, 'diff' => 51],
            ['prefix' => 'free', 'suffix' => 'templates', 'intent' => 'informational', 'mult_vol' => 4.1, 'diff' => 28],
            ['prefix' => 'enterprise', 'suffix' => 'solutions', 'intent' => 'transactional', 'mult_vol' => 0.6, 'diff' => 74],
        ];

        $results = [];

        // Exact seed keyword first
        $results[] = $this->buildMetricPayload($seed, $country, $language);

        foreach ($modifiers as $m) {
            $parts = array_filter([$m['prefix'], $seed, $m['suffix']]);
            $term = implode(' ', $parts);
            $results[] = $this->buildMetricPayload($term, $country, $language, $m['intent'], $m['mult_vol'], $m['diff']);
        }

        return $results;
    }

    /**
     * Build consistent metric payload adhering to provenance requirements.
     */
    private function buildMetricPayload(
        string $keyword,
        string $country,
        string $language,
        ?string $intent = null,
        float $volMultiplier = 1.0,
        ?float $diffOverride = null
    ): array {
        // Deterministic hash based on keyword characters
        $hash = crc32($keyword);
        $baseVolume = abs($hash % 8500) + 120;
        $volume = (int) round($baseVolume * $volMultiplier);

        // Intentionally create a few zero/missing volume cases for realistic testing
        $hasVolume = ($hash % 10 !== 0);
        $searchVolume = $hasVolume ? $volume : null;

        $difficulty = $diffOverride !== null ? $diffOverride : (float) (abs($hash % 65) + 15);
        $cpc = round((abs($hash % 900) / 100) + 0.85, 2);

        $intents = ['informational', 'commercial', 'transactional', 'navigational'];
        $resolvedIntent = $intent ?? $intents[abs($hash % 4)];

        $competition = $difficulty > 60 ? 'HIGH' : ($difficulty > 35 ? 'MEDIUM' : 'LOW');

        return [
            'keyword' => $keyword,
            'country' => $country,
            'language' => $language,
            'search_volume' => $searchVolume,
            'difficulty' => $difficulty,
            'cpc' => $cpc,
            'intent' => $resolvedIntent,
            'competition_level' => $competition,
            'provider' => $this->providerName,
            'provenance' => [
                'provider' => $this->providerName,
                'fetched_at' => now()->toIso8601String(),
                'country' => $country,
                'language' => $language,
                'is_stale' => false,
                'missing_metric' => !$hasVolume,
                'provenance_note' => $hasVolume ? 'Actual provider metrics recorded' : 'Search volume not returned by provider (unranked/low data)',
            ],
            'related_clusters' => [
                'primary_topic' => $keyword,
                'serp_features' => ['featured_snippet', 'people_also_ask', 'site_links'],
            ],
        ];
    }
}
