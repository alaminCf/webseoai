<?php

namespace App\Domain\KeywordIntelligence\Providers;

use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\KeywordIntelligence\Contracts\KeywordIntelligenceProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Live Google Autocomplete (Suggest) keyword discovery adapter.
 * Uses Google's public suggestqueries endpoint — zero API key required,
 * strictly returns real search suggestions with null metrics (never fabricated).
 */
class GoogleAutocompleteAdapter implements KeywordIntelligenceProviderInterface
{
    private const ENDPOINT = 'https://suggestqueries.google.com/complete/search';

    public function getName(): string
    {
        return 'google_autocomplete';
    }

    public function getLabel(): string
    {
        return 'Google Autocomplete (Live Suggest)';
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        return true;
    }

    public function supportedCapabilities(): array
    {
        return ['autocomplete', 'suggestions', 'questions', 'long_tail'];
    }

    public function fetchKeywordIdeas(
        string $seed,
        string $country = 'US',
        string $language = 'en',
        array $options = []
    ): array {
        $cleanSeed = trim($seed);
        if ($cleanSeed === '') {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'Seed keyword is empty.'];
        }

        $sources = $options['sources'] ?? ['autocomplete', 'suggestions', 'questions', 'long_tail'];
        $gl = CountryCatalog::gl($country);
        $hl = strtolower(trim($language)) ?: 'en';

        // Build bounded query set for live Google Suggest
        $queries = [];
        if (in_array('autocomplete', $sources, true) || in_array('suggestions', $sources, true)) {
            $queries[] = ['q' => $cleanSeed, 'source' => 'autocomplete'];
            $queries[] = ['q' => "{$cleanSeed} ", 'source' => 'suggestions'];
        }

        if (in_array('questions', $sources, true)) {
            foreach (['how', 'what', 'why', 'best', 'can', 'which'] as $prefix) {
                $queries[] = ['q' => "{$prefix} {$cleanSeed}", 'source' => 'questions'];
            }
        }

        if (in_array('long_tail', $sources, true)) {
            foreach (['for', 'vs', 'near me', 'cost', 'guide', 'tips'] as $modifier) {
                $queries[] = ['q' => "{$cleanSeed} {$modifier}", 'source' => 'long_tail'];
            }
        }

        if (empty($queries)) {
            $queries[] = ['q' => $cleanSeed, 'source' => 'autocomplete'];
        }

        // Cap outbound suggest queries per seed to keep latency crisp
        $maxSubQueries = (int) ($options['max_suggest_queries'] ?? 10);
        $queries = array_slice($queries, 0, max(1, min(14, $maxSubQueries)));

        try {
            $responses = Http::pool(function ($pool) use ($queries, $gl, $hl) {
                foreach ($queries as $idx => $entry) {
                    $pool->as("q_{$idx}")
                        ->timeout(5)
                        ->withHeaders([
                            'User-Agent' => 'Mozilla/5.0 (compatible; WebSeoAiBot/2.0)',
                            'Accept' => 'application/json',
                        ])
                        ->get(self::ENDPOINT, [
                            'client' => 'firefox',
                            'hl' => $hl,
                            'gl' => $gl,
                            'q' => $entry['q'],
                        ]);
                }
            });

            $seen = [];
            $keywords = [];
            $successCount = 0;

            foreach ($queries as $idx => $entry) {
                $resp = $responses["q_{$idx}"] ?? null;
                if (!$resp || ($resp instanceof Throwable) || !$resp->successful()) {
                    continue;
                }

                $successCount++;
                $decoded = $resp->json();
                $suggestions = is_array($decoded) && isset($decoded[1]) && is_array($decoded[1]) ? $decoded[1] : [];

                foreach ($suggestions as $phrase) {
                    $kw = trim((string) $phrase);
                    $norm = strtolower(preg_replace('/\s+/', ' ', $kw));
                    if ($norm === '' || isset($seen[$norm]) || mb_strlen($norm) > 220) {
                        continue;
                    }
                    $seen[$norm] = true;

                    $keywords[] = [
                        'keyword' => $kw,
                        'discovery_source' => $entry['source'],
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
            }

            if ($successCount === 0) {
                return [
                    'available' => false,
                    'provider' => $this->getName(),
                    'keywords' => [],
                    'error' => 'Google Autocomplete endpoint did not return a response.',
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'keywords' => $keywords,
                'credits_used' => 0,
                'cost_usd' => 0.0,
            ];
        } catch (Throwable $e) {
            return [
                'available' => false,
                'provider' => $this->getName(),
                'keywords' => [],
                'error' => $e->getMessage(),
            ];
        }
    }
}
