<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Domain\Competitors\Support\CountryCatalog;
use App\Models\PlatformApiKey;
use App\Models\WebsiteIntegration;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Serper.dev Google Search API adapter (2,500 free queries on signup).
 * Supports multi-key pool auto-failover, workspace BYOK keys, and server env keys.
 */
class SerperProvider implements CompetitorProviderInterface
{
    private string $apiKey;
    private ?string $runtimeApiKey = null;
    private ?int $activeOrganizationId = null;
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

    /**
     * Resolve the platform-wide shared Serper.dev integration configured by the platform owner
     * (any connected Serper integration in an `agency_internal` organization or explicitly
     * marked `platform_shared = true`), so every new user gets Live Google Search automatically
     * after login without having to set up their own API keys.
     */
    public static function resolvePlatformSharedIntegration(): ?WebsiteIntegration
    {
        try {
            $internalOrgIds = \App\Models\Organization::where('billing_plan', 'agency_internal')->pluck('id');

            $query = WebsiteIntegration::where('provider', WebsiteIntegration::PROVIDER_SERPER)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->orderByDesc('updated_at');

            if ($internalOrgIds->isNotEmpty()) {
                $internalMatch = (clone $query)->whereIn('organization_id', $internalOrgIds)->first();
                if ($internalMatch) {
                    return $internalMatch;
                }
            }

            // Fallback: check any integration explicitly tagged platform_shared
            foreach ($query->limit(10)->get() as $candidate) {
                if (!empty($candidate->metadata['platform_shared'])) {
                    return $candidate;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolvePlatformSharedApiKey(): string
    {
        $integration = self::resolvePlatformSharedIntegration();
        if (!$integration) {
            return '';
        }

        $creds = $integration->getDecryptedCredentials();
        if (is_array($creds)) {
            return trim((string) ($creds['api_key'] ?? ''));
        }

        return is_string($creds) ? trim($creds) : '';
    }

    /**
     * Resolve ordered candidate Serper API keys for automatic multi-key pool failover.
     *
     * @return array<int, array{key: string, source: string, model: ?PlatformApiKey}>
     */
    public function resolveCandidateKeys(?int $organizationId = null): array
    {
        if ($this->runtimeApiKey !== null && $this->runtimeApiKey !== '') {
            return [['key' => $this->runtimeApiKey, 'source' => 'runtime', 'model' => null]];
        }

        // If this workspace saved its own BYOK Serper key, use ONLY that key (never consume platform credits)
        $workspaceKey = self::resolveWorkspaceApiKey($organizationId);
        if ($workspaceKey !== '') {
            return [['key' => $workspaceKey, 'source' => 'workspace_byok', 'model' => null]];
        }

        $candidates = [];
        $seen = [];

        // 1. Admin-managed Multi-Key Pool (ordered by priority ASC, id ASC)
        try {
            $poolKeys = PlatformApiKey::where('provider', 'serper')
                ->where('status', PlatformApiKey::STATUS_ACTIVE)
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            foreach ($poolKeys as $pk) {
                $raw = $pk->getDecryptedKey();
                if ($raw !== '' && !isset($seen[$raw])) {
                    $seen[$raw] = true;
                    $candidates[] = [
                        'key' => $raw,
                        'source' => 'platform_pool',
                        'model' => $pk,
                    ];
                }
            }
        } catch (Throwable) {
            // Table may not exist yet during early boot
        }

        // 2. Server environment variable (SERPER_API_KEY)
        $envKey = self::resolveEnvApiKey();
        if ($envKey !== '' && !isset($seen[$envKey])) {
            $seen[$envKey] = true;
            $candidates[] = [
                'key' => $envKey,
                'source' => 'server_env',
                'model' => null,
            ];
        }

        // 3. Legacy platform owner (agency_internal) saved Serper integration
        $legacyKey = self::resolvePlatformSharedApiKey();
        if ($legacyKey !== '' && !isset($seen[$legacyKey])) {
            $seen[$legacyKey] = true;
            $candidates[] = [
                'key' => $legacyKey,
                'source' => 'platform_legacy',
                'model' => null,
            ];
        }

        return $candidates;
    }

    public function resolveEffectiveApiKey(?int $organizationId = null): string
    {
        $candidates = $this->resolveCandidateKeys($organizationId);
        return $candidates[0]['key'] ?? '';
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
        if ($organizationId !== null) {
            $this->activeOrganizationId = $organizationId;
        }
        $this->apiKey = $this->resolveEffectiveApiKey($this->activeOrganizationId);
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
                    : "Serper.dev rejected this key (HTTP {$res->status()}). Please check your API key at serper.dev.",
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

    public function fetchSerp(
        string $keyword,
        string $country = 'US',
        string $language = 'en',
        string $device = 'desktop',
        ?string $location = null,
        int $depth = 20,
        ?string $stopWhenDomainFound = null
    ): array {
        if (!$this->isConfigured()) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'SERPER_API_KEY is not configured.'];
        }

        try {
            $gl = CountryCatalog::gl($country);
            $geoLocation = trim((string) ($location ?? '')) ?: CountryCatalog::serperLocation($country);
            $targetDepth = max(10, min(100, $depth));
            $maxPages = (int) ceil($targetDepth / 10);
            $normStopDomain = $stopWhenDomainFound
                ? preg_replace('/^www\./i', '', strtolower(trim($stopWhenDomainFound)))
                : null;

            $basePayload = [
                'q' => $keyword,
                'gl' => $gl,
                'hl' => strtolower($language),
                'num' => 10,
            ];
            if ($geoLocation !== '') {
                $basePayload['location'] = $geoLocation;
            }
            if (strtolower($device) === 'mobile') {
                $basePayload['device'] = 'mobile';
            }

            // Step 1: Fetch Google Page 1 with Multi-Key Pool Auto-Failover
            // If Key #1 is exhausted (400/402/429/credit error), immediately mark exhausted and retry with Key #2!
            $candidates = $this->resolveCandidateKeys($this->activeOrganizationId);
            if (empty($candidates)) {
                return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => 'SERPER_API_KEY is not configured.'];
            }

            $page1Payload = array_merge($basePayload, ['page' => 1]);
            $res1 = null;
            $activeKey = '';
            $activeSource = 'server_env';
            /** @var ?PlatformApiKey $activeModel */
            $activeModel = null;
            $lastFailureMsg = 'Serper.dev request failed.';
            $lastFailureStatus = 500;

            foreach ($candidates as $cand) {
                $attemptRes = Http::withHeaders([
                    'X-API-KEY' => $cand['key'],
                    'Content-Type' => 'application/json',
                ])->timeout($this->timeout)->post("{$this->baseUrl}/search", $page1Payload);

                if ($attemptRes->successful()) {
                    $res1 = $attemptRes;
                    $activeKey = $cand['key'];
                    $activeSource = $cand['source'];
                    $activeModel = $cand['model'];
                    $this->apiKey = $activeKey;
                    break;
                }

                $status = $attemptRes->status();
                $bodyMsg = trim((string) ($attemptRes->json('message') ?? $attemptRes->json('error') ?? $attemptRes->body()));
                $lowerBody = strtolower($bodyMsg);
                $lastFailureStatus = $status;
                $lastFailureMsg = $bodyMsg !== '' ? "Serper.dev HTTP {$status}: {$bodyMsg}" : "Serper.dev request failed (HTTP {$status})";

                $isExhausted = in_array($status, [400, 402, 429], true)
                    || str_contains($lowerBody, 'not enough credits')
                    || str_contains($lowerBody, 'credit')
                    || str_contains($lowerBody, 'quota')
                    || str_contains($lowerBody, 'limit');
                $isAuthFailure = in_array($status, [401, 403], true);

                if ($cand['model'] instanceof PlatformApiKey) {
                    if ($isExhausted) {
                        $cand['model']->markExhausted($lastFailureMsg);
                    } elseif ($isAuthFailure) {
                        $cand['model']->markError($lastFailureMsg);
                    }
                }

                // Auto-failover to next active pool key on exhaustion or auth failure
                if ($isExhausted || $isAuthFailure) {
                    continue;
                }

                // Non-key error (e.g. 5xx outage): stop retrying
                break;
            }

            if (!$res1 || !$res1->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'http_status' => $lastFailureStatus, 'error' => $lastFailureMsg];
            }

            $json1 = $res1->json() ?? [];
            $pagesRaw = [1 => $json1];
            $pagesFetched = 1;

            $page1OrganicCount = count($json1['organic'] ?? []);
            $domainMatchesPage = function (array $pageJson) use ($normStopDomain): bool {
                if (!$normStopDomain) {
                    return false;
                }
                foreach ($pageJson['organic'] ?? [] as $item) {
                    $host = preg_replace('/^www\./i', '', strtolower((string) parse_url((string) ($item['link'] ?? ''), PHP_URL_HOST)));
                    if ($host !== '' && ($host === $normStopDomain || str_ends_with($host, '.' . $normStopDomain))) {
                        return true;
                    }
                }
                return false;
            };

            $foundTargetPage = $domainMatchesPage($json1) ? 1 : null;

            // Step 2: Google caps Page 1 at ~10 results. Paginate Pages 2..$maxPages in fast parallel waves
            // so we find deep rankings (e.g. #25, #48, #73) and return the full continuous list down to the target!
            if ($maxPages > 1 && $page1OrganicCount > 0 && $page1OrganicCount <= 12 && $foundTargetPage === null) {
                $waves = [];
                if ($maxPages >= 2) {
                    $waves[] = range(2, min(5, $maxPages));
                }
                if ($maxPages >= 6) {
                    $waves[] = range(6, min(10, $maxPages));
                }

                foreach ($waves as $wavePages) {
                    $poolResponses = Http::pool(function ($pool) use ($wavePages, $basePayload) {
                        foreach ($wavePages as $pg) {
                            $pool->as("p{$pg}")
                                ->withHeaders([
                                    'X-API-KEY' => $this->apiKey,
                                    'Content-Type' => 'application/json',
                                ])
                                ->timeout($this->timeout)
                                ->post("{$this->baseUrl}/search", array_merge($basePayload, ['page' => $pg]));
                        }
                    });

                    $waveHadResults = false;
                    foreach ($wavePages as $pg) {
                        $resp = $poolResponses["p{$pg}"] ?? null;
                        if ($resp && !($resp instanceof Throwable) && $resp->successful()) {
                            $pJson = $resp->json() ?? [];
                            if (!empty($pJson['organic'])) {
                                $pagesRaw[$pg] = $pJson;
                                $pagesFetched++;
                                $waveHadResults = true;
                                if ($foundTargetPage === null && $domainMatchesPage($pJson)) {
                                    $foundTargetPage = $pg;
                                }
                            }
                        }
                    }

                    // Stop immediately once we found the target domain's Google page (saves credits & latency)
                    // or if Google returned no further pages
                    if ($foundTargetPage !== null || !$waveHadResults) {
                        break;
                    }
                }
            }

            // If we found the target domain in a wave (e.g. Page 3 inside Wave 2-5), trim pages AFTER that page
            // when stopWhenDomainFound is active so the list runs cleanly from #1 straight down through the target's page
            if ($normStopDomain !== null && $foundTargetPage !== null) {
                ksort($pagesRaw);
                $pagesRaw = array_filter(
                    $pagesRaw,
                    fn (int $pg) => $pg <= $foundTargetPage,
                    ARRAY_FILTER_USE_KEY
                );
            } else {
                ksort($pagesRaw);
            }

            $features = [];
            foreach (['answerBox' => 'featured_snippet', 'peopleAlsoAsk' => 'people_also_ask', 'places' => 'local_pack', 'knowledgeGraph' => 'knowledge_panel', 'shopping' => 'shopping', 'videos' => 'video_carousel', 'topStories' => 'top_stories'] as $k => $label) {
                if (!empty($json1[$k])) {
                    $features[] = $label;
                }
            }

            // Build deduplicated continuous organic results (#1..#100) preserving exact Google Page & rank on page
            $organic = [];
            $seenUrls = [];
            $globalPos = 0;
            $isMultiPage = count($pagesRaw) > 1;

            foreach ($pagesRaw as $pgNum => $pgJson) {
                $rankOnThisPage = 0;
                foreach ($pgJson['organic'] ?? [] as $idx => $item) {
                    $url = trim((string) ($item['link'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $urlKey = rtrim(strtolower($url), '/');
                    if (isset($seenUrls[$urlKey])) {
                        continue;
                    }
                    $seenUrls[$urlKey] = true;

                    $globalPos++;
                    $rankOnThisPage++;
                    $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

                    if ($isMultiPage) {
                        $pos = $globalPos;
                        $googlePage = (int) $pgNum;
                        $rankOnPage = $rankOnThisPage;
                    } else {
                        $pos = (int) ($item['position'] ?? $globalPos);
                        $googlePage = (int) ceil($pos / 10);
                        $rankOnPage = (($pos - 1) % 10) + 1;
                    }

                    $organic[] = [
                        'position' => $pos,
                        'google_page' => $googlePage,
                        'rank_on_page' => $rankOnPage,
                        'domain' => $host,
                        'url' => $url,
                        'title' => (string) ($item['title'] ?? ''),
                        'snippet' => (string) ($item['snippet'] ?? ''),
                        'date' => $item['date'] ?? null,
                        'sitelinks' => array_slice(is_array($item['sitelinks'] ?? null) ? $item['sitelinks'] : [], 0, 4),
                    ];
                }
            }

            $places = [];
            foreach ($json1['places'] ?? [] as $pIdx => $pl) {
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
            foreach ($json1['peopleAlsoAsk'] ?? [] as $q) {
                $paa[] = [
                    'question' => (string) ($q['question'] ?? ''),
                    'snippet' => (string) ($q['snippet'] ?? ''),
                    'title' => (string) ($q['title'] ?? ''),
                    'link' => (string) ($q['link'] ?? ''),
                ];
            }

            $related = [];
            foreach ($json1['relatedSearches'] ?? [] as $r) {
                if (!empty($r['query'])) {
                    $related[] = (string) $r['query'];
                }
            }

            if ($activeModel instanceof PlatformApiKey) {
                $activeModel->recordUsage($pagesFetched);
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res1->status(),
                'cost_usd' => round($pagesFetched * 0.001, 4),
                'pages_fetched' => $pagesFetched,
                'platform_api_key_id' => $activeModel?->id,
                'key_source' => $activeSource,
                'serp_features' => $features,
                'organic_results' => $organic,
                'local_pack' => $places,
                'people_also_ask' => $paa,
                'related_searches' => $related,
                'answer_box' => $json1['answerBox'] ?? null,
                'search_parameters' => [
                    'q' => $keyword,
                    'gl' => $gl,
                    'hl' => strtolower($language),
                    'location' => $geoLocation,
                    'device' => $device,
                    'depth' => $targetDepth,
                    'pages_fetched' => $pagesFetched,
                ],
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => $e->getMessage()];
        }
    }
}
