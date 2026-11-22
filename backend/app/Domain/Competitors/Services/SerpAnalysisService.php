<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Models\CompetitorDomain;
use App\Models\CompetitorSerpSnapshot;
use App\Models\Website;

class SerpAnalysisService
{
    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Execute SERP snapshot analysis via configured SERP provider (DataForSEO SERP or SerpApi).
     * Never claims a third-party SERP snapshot is a live Google ranking and never fabricates SERP results.
     */
    public function analyzeKeyword(
        Website $website,
        string $keyword,
        string $country = 'US',
        string $language = 'en',
        string $device = 'desktop',
        ?string $location = null,
        bool $monitor = false,
        bool $forceRefresh = false
    ): array {
        $keyword = trim($keyword);
        $country = strtoupper(trim($country)) ?: 'US';
        $language = strtolower(trim($language)) ?: 'en';
        $device = strtolower(trim($device)) === 'mobile' ? 'mobile' : 'desktop';

        $provider = $this->providerManager->resolveForCapability('serp');
        if ($provider->getName() === 'first_party_crawl') {
            return [
                'available' => false,
                'provider' => 'unconfigured',
                'snapshot' => null,
                'error' => 'No SERP data provider is connected. Configure DATAFORSEO_LOGIN/PASSWORD or SERPAPI_KEY in server environment variables to capture SERP snapshots.',
            ];
        }

        $res = $this->providerManager->cachedCall(
            $website,
            'serp',
            "{$provider->getName()}:serp:{$keyword}:{$country}:{$language}:{$device}:" . ($location ?? ''),
            "serp:{$keyword}",
            fn () => $provider->fetchSerp($keyword, $country, $language, $device, $location),
            $forceRefresh
        );

        if (!($res['available'] ?? false)) {
            return [
                'available' => false,
                'provider' => $provider->getName(),
                'snapshot' => null,
                'error' => $res['error'] ?? 'SERP provider request failed.',
            ];
        }

        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $competitors = CompetitorDomain::where('website_id', $website->id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (CompetitorDomain $c) => strtolower($c->domain));

        $targetPosition = null;
        $competitorPresence = [];
        $annotatedResults = [];

        foreach ($res['organic_results'] ?? [] as $row) {
            $domain = CompetitorDomain::normalizeDomain((string) ($row['domain'] ?? ($row['url'] ?? '')));
            $pos = (int) ($row['position'] ?? (count($annotatedResults) + 1));
            $isTarget = $domain === $targetDomain;
            $compModel = $competitors->get($domain);

            if ($isTarget && $targetPosition === null) {
                $targetPosition = $pos;
            }
            if ($compModel && !isset($competitorPresence[$domain])) {
                $competitorPresence[$domain] = $pos;
            }

            $annotatedResults[] = [
                'position' => $pos,
                'domain' => $domain,
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'snippet' => (string) ($row['snippet'] ?? ''),
                'is_target' => $isTarget,
                'is_competitor' => $compModel !== null,
                'competitor_category' => $compModel?->category,
            ];
        }

        // Extract observed content patterns from top 10 titles/URLs
        $patterns = $this->extractContentPatterns($annotatedResults);
        $intent = $this->inferSerpIntent($keyword, $annotatedResults, $res['serp_features'] ?? []);

        $snapshot = CompetitorSerpSnapshot::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'keyword' => $keyword,
            'country' => $country,
            'language' => $language,
            'search_engine' => 'google',
            'device' => $device,
            'location' => $location,
            'is_monitored' => $monitor,
            'search_intent' => $intent,
            'serp_features' => $res['serp_features'] ?? [],
            'organic_results' => $annotatedResults,
            'target_position' => $targetPosition,
            'competitor_presence' => $competitorPresence,
            'content_patterns' => $patterns,
            'data_source' => $provider->getName(),
            'captured_at' => now(),
        ]);

        // Compare with previous snapshot for the same keyword/country/device to compute rank delta
        $previous = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->where('keyword', $keyword)
            ->where('country', $country)
            ->where('device', $device)
            ->where('id', '<', $snapshot->id)
            ->orderByDesc('captured_at')
            ->first();

        $delta = null;
        if ($previous && $previous->target_position !== null && $targetPosition !== null) {
            $delta = $previous->target_position - $targetPosition; // positive means improved
        }

        return [
            'available' => true,
            'provider' => $provider->getName(),
            'disclaimer' => 'SERP results represent a third-party provider snapshot at capture time and are not guaranteed to match live personalized Google search results.',
            'snapshot' => $snapshot,
            'previous_target_position' => $previous?->target_position,
            'position_delta' => $delta,
        ];
    }

    private function extractContentPatterns(array $results): array
    {
        $top = array_slice($results, 0, 10);
        $guides = 0;
        $listicles = 0;
        $commercial = 0;
        $avgTitleLen = 0;

        foreach ($top as $r) {
            $t = strtolower((string) ($r['title'] ?? ''));
            $avgTitleLen += mb_strlen($r['title'] ?? '');
            if (preg_match('/\b(guide|how to|what is|tutorial|complete)\b/', $t)) {
                $guides++;
            }
            if (preg_match('/\b(\d+|best|top|list|checklist)\b/', $t)) {
                $listicles++;
            }
            if (preg_match('/\b(pricing|service|agency|company|buy|book|hire)\b/', $t)) {
                $commercial++;
            }
        }

        $count = max(1, count($top));
        return [
            'avg_top10_title_length' => (int) round($avgTitleLen / $count),
            'guide_format_count' => $guides,
            'listicle_format_count' => $listicles,
            'commercial_landing_count' => $commercial,
            'dominant_format' => $commercial >= max($guides, $listicles)
                ? 'Commercial / Service Landing Pages'
                : ($listicles >= $guides ? 'Listicles & Comparison Pages' : 'In-Depth Guides & Educational Content'),
        ];
    }

    private function inferSerpIntent(string $keyword, array $results, array $features): string
    {
        if (in_array('shopping', $features, true) || preg_match('/\b(buy|price|pricing|hire|book|near me|service)\b/i', $keyword)) {
            return 'transactional';
        }
        if (preg_match('/\b(best|top|vs|review|compare|alternative)\b/i', $keyword)) {
            return 'commercial';
        }
        if (in_array('featured_snippet', $features, true) || in_array('people_also_ask', $features, true)) {
            return 'informational';
        }
        return 'informational';
    }
}
