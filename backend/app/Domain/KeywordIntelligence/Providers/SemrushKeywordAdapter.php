<?php

namespace App\Domain\KeywordIntelligence\Providers;

use App\Domain\KeywordIntelligence\Contracts\KeywordIntelligenceProviderInterface;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Optional Semrush Keyword API adapter (when SEMRUSH_API_KEY is configured).
 * Returns provider-verified volume, KD, CPC, competition, and trend array without fabrication.
 */
class SemrushKeywordAdapter implements KeywordIntelligenceProviderInterface
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
        return 'Semrush Keyword API';
    }

    public function isConfigured(?int $organizationId = null): bool
    {
        return $this->apiKey !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['related', 'questions', 'volume', 'difficulty', 'cpc', 'competition'];
    }

    public function fetchKeywordIdeas(
        string $seed,
        string $country = 'US',
        string $language = 'en',
        array $options = []
    ): array {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => 'SEMRUSH_API_KEY is not configured.'];
        }

        $db = strtolower((string) (config('competitors.country_map.' . strtoupper($country) . '.semrush_db') ?? 'us'));
        $limit = max(10, min(80, (int) ($options['limit'] ?? 40)));

        try {
            $res = Http::timeout($this->timeout)->get($this->baseUrl . '/', [
                'type' => 'phrase_related',
                'key' => $this->apiKey,
                'phrase' => trim($seed),
                'database' => $db,
                'display_limit' => $limit,
                'export_columns' => 'Ph,Nq,Cp,Co,Kd',
            ]);

            if (!$res->successful() || str_starts_with(trim($res->body()), 'ERROR')) {
                return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => trim($res->body()) ?: "Semrush HTTP {$res->status()}"];
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($res->body())) ?: [];
            $keywords = [];
            foreach (array_slice($lines, 1) as $line) {
                $cols = explode(';', $line);
                $kw = trim((string) ($cols[0] ?? ''));
                if ($kw === '') {
                    continue;
                }
                $comp = isset($cols[3]) && is_numeric($cols[3]) ? round((float) $cols[3], 4) : null;
                $keywords[] = [
                    'keyword' => $kw,
                    'discovery_source' => 'related',
                    'data_source' => $this->getName(),
                    'metric_classification' => 'third_party_estimated',
                    'search_volume' => isset($cols[1]) && is_numeric($cols[1]) ? (int) $cols[1] : null,
                    'cpc' => isset($cols[2]) && is_numeric($cols[2]) ? round((float) $cols[2], 2) : null,
                    'paid_competition' => $comp,
                    'competition_level' => $comp !== null ? ($comp >= 0.67 ? 'high' : ($comp >= 0.34 ? 'medium' : 'low')) : null,
                    'keyword_difficulty' => isset($cols[4]) && is_numeric($cols[4]) ? round((float) $cols[4], 2) : null,
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
                'credits_used' => count($keywords),
                'cost_usd' => 0.0,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => $e->getMessage()];
        }
    }
}
