<?php

namespace App\Domain\Competitors\Providers;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Models\PlatformApiKey;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Unified DataForSEO v3 adapter covering Domain Metrics, Competitor Discovery,
 * Ranked Keywords, Backlinks, and Live Google Organic SERP.
 * Resolves credentials from environment variables OR Super Admin encrypted PlatformApiKey.
 */
class DataForSeoProvider implements CompetitorProviderInterface
{
    private string $login;
    private string $password;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $creds = self::resolveCredentials();
        $this->login = $creds['login'];
        $this->password = $creds['password'];
        $this->baseUrl = rtrim((string) config('competitors.providers.dataforseo.base_url', 'https://api.dataforseo.com/v3'), '/');
        $this->timeout = (int) config('competitors.http_timeout_seconds', 15);
    }

    /**
     * Resolve DataForSEO login & password from config/env first, then Admin Panel encrypted storage.
     *
     * @return array{login: string, password: string, source: string}
     */
    public static function resolveCredentials(): array
    {
        $envLogin = trim((string) config('competitors.providers.dataforseo.login', ''));
        $envPass = trim((string) config('competitors.providers.dataforseo.password', ''));
        if ($envLogin !== '' && $envPass !== '') {
            return ['login' => $envLogin, 'password' => $envPass, 'source' => 'server_env'];
        }

        try {
            $key = PlatformApiKey::where('provider', 'dataforseo')
                ->where('status', PlatformApiKey::STATUS_ACTIVE)
                ->orderBy('priority')
                ->orderBy('id')
                ->first();

            if ($key) {
                $decoded = json_decode($key->getDecryptedKey(), true);
                if (is_array($decoded) && !empty($decoded['login']) && !empty($decoded['password'])) {
                    return [
                        'login' => trim((string) $decoded['login']),
                        'password' => trim((string) $decoded['password']),
                        'source' => 'admin_panel',
                    ];
                }
            }
        } catch (Throwable) {
            // Table may not exist during early boot
        }

        return ['login' => '', 'password' => '', 'source' => 'unconfigured'];
    }

    /**
     * Verify DataForSEO credentials via the free /appendix/user_data endpoint (costs $0.00).
     */
    public function verifyCredentials(string $login, string $password): array
    {
        $cleanLogin = trim($login);
        $cleanPass = trim($password);
        if ($cleanLogin === '' || $cleanPass === '') {
            return ['valid' => false, 'error' => 'Both DataForSEO login email and API password are required.'];
        }

        try {
            $res = Http::withBasicAuth($cleanLogin, $cleanPass)
                ->timeout(12)
                ->acceptJson()
                ->get($this->baseUrl . '/appendix/user_data');

            if (!$res->successful()) {
                return [
                    'valid' => false,
                    'error' => "DataForSEO rejected credentials (HTTP {$res->status()}). Check your API Login & API Password at app.dataforseo.com.",
                ];
            }

            $statusCode = (int) ($res->json('status_code') ?? 0);
            if ($statusCode !== 20000) {
                $msg = (string) ($res->json('status_message') ?? 'Invalid DataForSEO credentials.');
                return ['valid' => false, 'error' => "DataForSEO verification failed: {$msg}"];
            }

            $money = $res->json('tasks.0.result.0.money') ?? [];
            return [
                'valid' => true,
                'balance' => isset($money['balance']) ? round((float) $money['balance'], 4) : null,
                'total_deposited' => isset($money['total']) ? round((float) $money['total'], 4) : null,
            ];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'error' => 'Could not reach DataForSEO API: ' . $e->getMessage(),
            ];
        }
    }

    public function getName(): string
    {
        return 'dataforseo';
    }

    public function getLabel(): string
    {
        return 'DataForSEO v3';
    }

    public function isConfigured(): bool
    {
        $creds = self::resolveCredentials();
        $this->login = $creds['login'];
        $this->password = $creds['password'];
        return $this->login !== '' && $this->password !== '';
    }

    public function supportedCapabilities(): array
    {
        return ['discovery', 'domain_metrics', 'keywords', 'backlinks', 'serp'];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->supportedCapabilities(), true);
    }

    public function discoverCompetitors(string $domain, string $country = 'US', string $language = 'en', array $context = []): array
    {
        if (!$this->isConfigured()) {
            return $this->unconfigured('discovery');
        }

        $locationCode = $this->locationCode($country);
        $endpoint = '/dataforseo_labs/google/competitors_domain/live';

        try {
            $res = $this->client()->post($this->baseUrl . $endpoint, [[
                'target' => $domain,
                'location_code' => $locationCode,
                'language_code' => strtolower($language),
                'exclude_top_domains' => true,
                'limit' => 20,
            ]]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'http_status' => $res->status(), 'error' => 'DataForSEO discovery failed (HTTP ' . $res->status() . ')'];
            }

            $task = $res->json('tasks.0') ?? [];
            $items = $task['result'][0]['items'] ?? [];
            $candidates = [];

            foreach ($items as $item) {
                $candDomain = strtolower(trim((string) ($item['domain'] ?? '')));
                if ($candDomain === '' || $candDomain === strtolower($domain)) {
                    continue;
                }
                $intersections = (int) ($item['intersections'] ?? 0);
                $avgPos = (float) ($item['avg_position'] ?? 50);
                $etv = isset($item['full_domain_metrics']['organic']['etv']) ? (int) round($item['full_domain_metrics']['organic']['etv']) : null;

                $candidates[] = [
                    'domain' => $candDomain,
                    'shared_keywords' => $intersections,
                    'avg_position' => round($avgPos, 1),
                    'organic_traffic_estimate' => $etv,
                    'serp_overlap_pct' => min(100, round(min(100, $intersections * 2.5), 1)),
                    'sample_urls' => [],
                    'source' => 'dataforseo',
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'cost_usd' => (float) ($task['cost'] ?? 0.01),
                'methodology' => "DataForSEO Labs Google Competitors Domain API ({$country}/{$language}) measuring shared organic ranking keywords and SERP intersection.",
                'candidates' => $candidates,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'methodology' => '', 'candidates' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchDomainMetrics(string $domain, string $country = 'US', string $language = 'en'): array
    {
        if (!$this->isConfigured()) {
            return $this->unconfigured('domain_metrics');
        }

        $locationCode = $this->locationCode($country);
        $endpoint = '/dataforseo_labs/google/domain_rank_overview/live';

        try {
            $res = $this->client()->post($this->baseUrl . $endpoint, [[
                'target' => $domain,
                'location_code' => $locationCode,
                'language_code' => strtolower($language),
            ]]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'http_status' => $res->status(), 'error' => 'DataForSEO domain overview failed (HTTP ' . $res->status() . ')'];
            }

            $task = $res->json('tasks.0') ?? [];
            $organic = $task['result'][0]['items'][0]['metrics']['organic'] ?? [];

            $pos1 = (int) ($organic['pos_1'] ?? 0);
            $pos23 = (int) ($organic['pos_2_3'] ?? 0);
            $pos410 = (int) ($organic['pos_4_10'] ?? 0);
            $pos1120 = (int) ($organic['pos_11_20'] ?? 0);
            $pos2150 = (int) (($organic['pos_21_30'] ?? 0) + ($organic['pos_31_40'] ?? 0) + ($organic['pos_41_50'] ?? 0));
            $pos51100 = (int) (($organic['pos_51_60'] ?? 0) + ($organic['pos_61_70'] ?? 0) + ($organic['pos_71_80'] ?? 0) + ($organic['pos_81_90'] ?? 0) + ($organic['pos_91_100'] ?? 0));
            $totalKw = isset($organic['count']) ? (int) $organic['count'] : null;

            return [
                'available' => true,
                'provider' => $this->getName(),
                'classification' => 'third_party_estimate',
                'http_status' => $res->status(),
                'cost_usd' => (float) ($task['cost'] ?? 0.01),
                'metrics' => [
                    'organic_traffic_estimate' => isset($organic['etv']) ? (int) round((float) $organic['etv']) : null,
                    'organic_keywords_count' => $totalKw,
                    'traffic_value_estimate' => isset($organic['estimated_paid_traffic_cost']) ? round((float) $organic['estimated_paid_traffic_cost'], 2) : null,
                    'referring_domains_count' => null,
                    'backlinks_count' => null,
                    'indexed_pages_count' => null,
                    'authority_score' => null,
                    'authority_metric_label' => 'DataForSEO Domain Rank',
                    'serp_visibility_score' => $totalKw && $totalKw > 0
                        ? round(min(100, (($pos1 * 3 + $pos23 * 2 + $pos410) / max(1, $totalKw)) * 100), 2)
                        : null,
                    'ranking_distribution' => [
                        'pos_1_3' => $pos1 + $pos23,
                        'pos_4_10' => $pos410,
                        'pos_11_20' => $pos1120,
                        'pos_21_50' => $pos2150,
                        'pos_51_100' => $pos51100,
                    ],
                    'top_keywords' => [],
                    'top_pages' => [],
                ],
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'classification' => 'third_party_estimate', 'metrics' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchDomainKeywords(string $domain, string $country = 'US', string $language = 'en', int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return $this->unconfigured('keywords');
        }

        $endpoint = '/dataforseo_labs/google/ranked_keywords/live';
        try {
            $res = $this->client()->post($this->baseUrl . $endpoint, [[
                'target' => $domain,
                'location_code' => $this->locationCode($country),
                'language_code' => strtolower($language),
                'limit' => min(100, max(10, $limit)),
            ]]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'http_status' => $res->status(), 'error' => 'DataForSEO ranked keywords failed (HTTP ' . $res->status() . ')'];
            }

            $task = $res->json('tasks.0') ?? [];
            $items = $task['result'][0]['items'] ?? [];
            $keywords = [];

            foreach ($items as $item) {
                $kwData = $item['keyword_data'] ?? [];
                $kwInfo = $kwData['keyword_info'] ?? [];
                $serpEl = $item['ranked_serp_element']['serp_item'] ?? [];
                $kw = trim((string) ($kwData['keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }

                $keywords[] = [
                    'keyword' => $kw,
                    'search_volume' => isset($kwInfo['search_volume']) ? (int) $kwInfo['search_volume'] : null,
                    'keyword_difficulty' => isset($kwData['keyword_properties']['keyword_difficulty']) ? (int) $kwData['keyword_properties']['keyword_difficulty'] : null,
                    'cpc' => isset($kwInfo['cpc']) ? round((float) $kwInfo['cpc'], 2) : null,
                    'search_intent' => $kwData['search_intent_info']['main_intent'] ?? null,
                    'position' => isset($serpEl['rank_absolute']) ? (int) $serpEl['rank_absolute'] : null,
                    'url' => $serpEl['url'] ?? null,
                    'serp_features' => $item['ranked_serp_element']['serp_item_types'] ?? [],
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'cost_usd' => (float) ($task['cost'] ?? 0.015),
                'keywords' => $keywords,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'keywords' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchBacklinks(string $domain, int $limit = 50): array
    {
        if (!$this->isConfigured()) {
            return $this->unconfigured('backlinks');
        }

        try {
            $summaryRes = $this->client()->post($this->baseUrl . '/backlinks/summary/live', [[
                'target' => $domain,
                'include_subdomains' => true,
            ]]);
            $blRes = $this->client()->post($this->baseUrl . '/backlinks/backlinks/live', [[
                'target' => $domain,
                'limit' => min(100, max(10, $limit)),
                'mode' => 'as_is',
            ]]);

            if (!$summaryRes->successful() && !$blRes->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'http_status' => $summaryRes->status(), 'error' => 'DataForSEO Backlinks API failed (HTTP ' . $summaryRes->status() . ')'];
            }

            $sumResult = $summaryRes->json('tasks.0.result.0') ?? [];
            $blItems = $blRes->json('tasks.0.result.0.items') ?? [];
            $backlinks = [];

            foreach ($blItems as $item) {
                $refDomain = strtolower(trim((string) ($item['domain_from'] ?? '')));
                if ($refDomain === '') {
                    continue;
                }
                $backlinks[] = [
                    'referring_domain' => $refDomain,
                    'referring_url' => $item['url_from'] ?? null,
                    'destination_url' => $item['url_to'] ?? null,
                    'anchor_text' => $item['anchor'] ?? null,
                    'link_type' => !empty($item['dofollow']) ? 'dofollow' : 'nofollow',
                    'referring_authority' => isset($item['domain_from_rank']) ? min(100, (int) round($item['domain_from_rank'] / 10)) : null,
                    'link_status' => !empty($item['is_lost']) ? 'lost' : (!empty($item['is_new']) ? 'new' : 'active'),
                    'first_seen_at' => $item['first_seen'] ?? null,
                    'last_seen_at' => $item['last_seen'] ?? null,
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $summaryRes->status(),
                'cost_usd' => (float) (($summaryRes->json('tasks.0.cost') ?? 0) + ($blRes->json('tasks.0.cost') ?? 0)),
                'summary' => [
                    'referring_domains_count' => isset($sumResult['referring_domains']) ? (int) $sumResult['referring_domains'] : null,
                    'backlinks_count' => isset($sumResult['backlinks']) ? (int) $sumResult['backlinks'] : null,
                    'authority_score' => isset($sumResult['rank']) ? min(100, (int) round($sumResult['rank'] / 10)) : null,
                    'authority_metric_label' => 'DataForSEO Rank',
                ],
                'backlinks' => $backlinks,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'summary' => [], 'backlinks' => [], 'error' => $e->getMessage()];
        }
    }

    public function fetchSerp(string $keyword, string $country = 'US', string $language = 'en', string $device = 'desktop', ?string $location = null): array
    {
        if (!$this->isConfigured()) {
            return $this->unconfigured('serp');
        }

        try {
            $res = $this->client()->post($this->baseUrl . '/serp/google/organic/live/advanced', [[
                'keyword' => $keyword,
                'location_code' => $this->locationCode($country),
                'language_code' => strtolower($language),
                'device' => strtolower($device),
                'depth' => 20,
            ]]);

            if (!$res->successful()) {
                return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'http_status' => $res->status(), 'error' => 'DataForSEO SERP failed (HTTP ' . $res->status() . ')'];
            }

            $task = $res->json('tasks.0') ?? [];
            $result = $task['result'][0] ?? [];
            $features = $result['item_types'] ?? [];
            $organic = [];

            foreach ($result['items'] ?? [] as $item) {
                if (($item['type'] ?? '') !== 'organic') {
                    continue;
                }
                $organic[] = [
                    'position' => (int) ($item['rank_group'] ?? $item['rank_absolute'] ?? (count($organic) + 1)),
                    'domain' => strtolower((string) ($item['domain'] ?? '')),
                    'url' => (string) ($item['url'] ?? ''),
                    'title' => (string) ($item['title'] ?? ''),
                    'snippet' => (string) ($item['description'] ?? ''),
                ];
            }

            return [
                'available' => true,
                'provider' => $this->getName(),
                'http_status' => $res->status(),
                'cost_usd' => (float) ($task['cost'] ?? 0.003),
                'serp_features' => array_values(array_unique(array_filter($features, fn ($f) => $f !== 'organic'))),
                'organic_results' => $organic,
            ];
        } catch (Throwable $e) {
            return ['available' => false, 'provider' => $this->getName(), 'serp_features' => [], 'organic_results' => [], 'error' => $e->getMessage()];
        }
    }

    private function client()
    {
        return Http::withBasicAuth($this->login, $this->password)
            ->acceptJson()
            ->timeout($this->timeout);
    }

    private function locationCode(string $country): int
    {
        return (int) (config('competitors.country_map.' . strtoupper($country) . '.location_code') ?? 2840);
    }

    private function unconfigured(string $capability): array
    {
        return [
            'available' => false,
            'provider' => $this->getName(),
            'classification' => 'third_party_estimate',
            'methodology' => '',
            'candidates' => [],
            'metrics' => [],
            'keywords' => [],
            'summary' => [],
            'backlinks' => [],
            'serp_features' => [],
            'organic_results' => [],
            'error' => "DataForSEO credentials (DATAFORSEO_LOGIN, DATAFORSEO_PASSWORD) are not configured for {$capability}.",
        ];
    }
}
