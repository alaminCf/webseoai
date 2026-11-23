<?php

namespace App\Domain\Traffic;

use App\Domain\Competitors\Support\CountryCatalog;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native Google Analytics 4 (GA4) Data API v1beta Realtime & Historical Traffic Service.
 * Reuses the existing Google OAuth 2.0 client & authorized redirect URI (zero extra redirect setup).
 */
class GoogleAnalyticsService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId = trim((string) config('search_console.client_id', ''));
        $this->clientSecret = trim((string) config('search_console.client_secret', ''));
        $configuredRedirect = trim((string) config('search_console.redirect_uri', ''));
        $this->redirectUri = $configuredRedirect !== ''
            ? $configuredRedirect
            : url('/api/v1/integrations/google-search-console/callback');
    }

    public function isLiveConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Generate Google OAuth 2.0 URL requesting BOTH GA4 analytics.readonly AND GSC webmasters.readonly.
     * Reuses the already-whitelisted Google Search Console OAuth callback URI with intent=ga4 in state.
     */
    public function getAuthorizationUrl(Website $website, int $userId): string
    {
        $statePayload = [
            'website_id' => $website->id,
            'organization_id' => $website->organization_id,
            'user_id' => $userId,
            'intent' => 'ga4',
            'timestamp' => now()->timestamp,
        ];
        $state = base64_encode(json_encode($statePayload));

        if (!$this->isLiveConfigured()) {
            return url('/api/v1/integrations/google-search-console/sandbox-callback') . '?' . http_build_query([
                'state' => $state,
                'code' => 'sandbox_ga4_auth_code',
            ]);
        }

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent select_account',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * Store GA4 integration from a freshly obtained or reused Google OAuth token set.
     * Never silently swallows Admin API errors — records exact diagnostics & enable URLs.
     */
    public function storeFromTokens(Website $website, array $credentials): WebsiteIntegration
    {
        $accessToken = (string) ($credentials['access_token'] ?? '');
        $scopeStr = (string) ($credentials['scope'] ?? '');
        $hasAnalyticsScope = $scopeStr === '' || str_contains($scopeStr, 'analytics.readonly');

        $fetchResult = $accessToken !== ''
            ? $this->fetchGa4Properties($accessToken)
            : ['properties' => [], 'error' => 'Missing Google OAuth access token.', 'enable_url' => null];

        $properties = $fetchResult['properties'];
        $selected = $this->matchBestProperty($website, $properties);

        // Preserve any previously manually-selected property ID if Admin API is disabled
        $existing = WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', WebsiteIntegration::PROVIDER_GA4)
            ->first();
        $existingMeta = is_array($existing?->metadata) ? $existing->metadata : [];

        $selectedId = $selected['property_id'] ?? ($existingMeta['selected_property_id'] ?? null);
        $selectedName = $selected['display_name'] ?? ($existingMeta['selected_property_name'] ?? ($selectedId ? "Property {$selectedId}" : null));

        return WebsiteIntegration::updateOrCreate(
            [
                'website_id' => $website->id,
                'provider' => WebsiteIntegration::PROVIDER_GA4,
            ],
            [
                'organization_id' => $website->organization_id,
                'encrypted_credentials' => $credentials,
                'status' => WebsiteIntegration::STATUS_CONNECTED,
                'last_error' => $fetchResult['error'],
                'last_synced_at' => now(),
                'metadata' => [
                    'properties' => $properties,
                    'selected_property_id' => $selectedId,
                    'selected_property_name' => $selectedName,
                    'granted_scope' => $scopeStr,
                    'has_analytics_scope' => $hasAnalyticsScope,
                    'admin_api_error' => $fetchResult['error'],
                    'admin_api_enable_url' => $fetchResult['enable_url'],
                    'is_sandbox' => (bool) ($credentials['is_sandbox'] ?? false),
                ],
            ]
        );
    }

    /**
     * Re-fetch GA4 properties using the already-connected Google token (e.g. right after enabling Analytics Admin API).
     */
    public function refreshProperties(Website $website, WebsiteIntegration $integration): array
    {
        try {
            $accessToken = $this->getValidAccessToken($integration);
        } catch (Throwable $e) {
            return [
                'properties' => [],
                'selected_property_id' => $integration->metadata['selected_property_id'] ?? null,
                'error' => 'Google OAuth token expired — please click "Reconnect Google Account". (' . $e->getMessage() . ')',
                'enable_url' => null,
            ];
        }

        $fetchResult = $this->fetchGa4Properties($accessToken);
        $properties = $fetchResult['properties'];
        $meta = is_array($integration->metadata) ? $integration->metadata : [];

        $selected = $this->matchBestProperty($website, $properties);
        if (empty($meta['selected_property_id']) && $selected) {
            $meta['selected_property_id'] = $selected['property_id'];
            $meta['selected_property_name'] = $selected['display_name'];
        }

        $meta['properties'] = $properties;
        $meta['admin_api_error'] = $fetchResult['error'];
        $meta['admin_api_enable_url'] = $fetchResult['enable_url'];

        $integration->update([
            'metadata' => $meta,
            'last_error' => $fetchResult['error'],
            'last_synced_at' => now(),
        ]);

        return [
            'properties' => $properties,
            'selected_property_id' => $meta['selected_property_id'] ?? null,
            'selected_property_name' => $meta['selected_property_name'] ?? null,
            'error' => $fetchResult['error'],
            'enable_url' => $fetchResult['enable_url'],
        ];
    }

    /**
     * Save a manually selected or entered GA4 Property ID (e.g. "412345678" or "properties/412345678").
     */
    public function selectProperty(WebsiteIntegration $integration, string $propertyId): WebsiteIntegration
    {
        $cleanId = preg_replace('/^properties\//', '', trim($propertyId));
        $meta = is_array($integration->metadata) ? $integration->metadata : [];
        $displayName = "Property {$cleanId}";
        foreach ($meta['properties'] ?? [] as $p) {
            if (($p['property_id'] ?? '') === $cleanId) {
                $displayName = $p['display_name'] ?? $displayName;
                break;
            }
        }
        $meta['selected_property_id'] = $cleanId;
        $meta['selected_property_name'] = $displayName;
        $integration->update([
            'metadata' => $meta,
            'last_error' => null,
        ]);

        return $integration->fresh();
    }

    /**
     * List all GA4 properties accessible by the authorized Google account, with full error diagnostics.
     *
     * @return array{properties: array, error: ?string, enable_url: ?string}
     */
    public function fetchGa4Properties(string $accessToken): array
    {
        try {
            $res = Http::withToken($accessToken)
                ->timeout(12)
                ->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', [
                    'pageSize' => 200,
                ]);

            if (!$res->successful()) {
                $errMsg = (string) ($res->json('error.message') ?? ("Google Analytics Admin API HTTP {$res->status()}"));
                $enableUrl = $this->extractEnableUrl($errMsg, 'https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com');
                Log::warning('GA4 Admin API accountSummaries failed', ['status' => $res->status(), 'error' => $errMsg]);

                return [
                    'properties' => [],
                    'error' => $errMsg,
                    'enable_url' => $enableUrl,
                ];
            }

            $out = [];
            foreach ($res->json('accountSummaries') ?? [] as $acc) {
                $accName = (string) ($acc['displayName'] ?? '');
                foreach ($acc['propertySummaries'] ?? [] as $prop) {
                    $rawProp = (string) ($prop['property'] ?? '');
                    $id = preg_replace('/^properties\//', '', $rawProp);
                    if ($id === '') {
                        continue;
                    }
                    $out[] = [
                        'property_id' => $id,
                        'resource_name' => "properties/{$id}",
                        'display_name' => (string) ($prop['displayName'] ?? "GA4 {$id}"),
                        'account_name' => $accName,
                    ];
                }
            }

            return [
                'properties' => $out,
                'error' => empty($out) ? 'No GA4 properties found on this Google account. Ensure this Google email has Viewer access in Google Analytics 4, or enter your numeric GA4 Property ID manually.' : null,
                'enable_url' => null,
            ];
        } catch (Throwable $e) {
            return [
                'properties' => [],
                'error' => 'Could not reach Google Analytics Admin API: ' . $e->getMessage(),
                'enable_url' => null,
            ];
        }
    }

    /**
     * Fetch live GA4 Realtime (last 30 mins) + Historical Range (24h/7d/30d) analytics
     * mapped into the unified Live Traffic dashboard format.
     */
    public function getRealtimeDashboard(
        Website $website,
        WebsiteIntegration $integration,
        string $range = '24h',
        ?string $countryFilter = null
    ): array {
        $meta = is_array($integration->metadata) ? $integration->metadata : [];
        $propertyId = preg_replace('/^properties\//', '', trim((string) ($meta['selected_property_id'] ?? '')));

        if ($propertyId === '') {
            $err = $meta['admin_api_error']
                ?: 'Google account is connected, but no GA4 Property ID is selected yet. Enable Google Analytics Admin API (or paste your numeric GA4 Property ID below) to start streaming live GA4 traffic.';
            return [
                'available' => false,
                'error' => $err,
                'enable_url' => $meta['admin_api_enable_url'] ?? 'https://console.cloud.google.com/apis/library/analyticsadmin.googleapis.com',
            ];
        }

        try {
            $accessToken = $this->getValidAccessToken($integration);
        } catch (Throwable $e) {
            return [
                'available' => false,
                'error' => 'GA4 authorization expired — please click "Reconnect Google Account" to refresh access. (' . $e->getMessage() . ')',
                'enable_url' => null,
            ];
        }

        $rtUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runRealtimeReport";
        $histUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";

        // 1. GA4 Live Realtime Report (last 30 minutes) — Country, City, Page, Device
        $rtGeoRes = Http::withToken($accessToken)->timeout(12)->post($rtUrl, [
            'dimensions' => [
                ['name' => 'country'],
                ['name' => 'countryId'],
                ['name' => 'city'],
                ['name' => 'unifiedScreenName'],
                ['name' => 'deviceCategory'],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
            ],
            'limit' => 250,
        ]);

        if (!$rtGeoRes->successful()) {
            $errMsg = (string) ($rtGeoRes->json('error.message') ?? ('GA4 Data API HTTP ' . $rtGeoRes->status()));
            $enableUrl = $this->extractEnableUrl($errMsg, 'https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com');
            $integration->update(['last_error' => $errMsg]);

            return [
                'available' => false,
                'error' => $errMsg,
                'enable_url' => $enableUrl,
            ];
        }

        // 2. GA4 Minute-by-Minute Realtime Timeline (last 30 mins) + 5-min Active Now count
        $rtMinRes = Http::withToken($accessToken)->timeout(10)->post($rtUrl, [
            'dimensions' => [['name' => 'minutesAgo']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
            ],
            'limit' => 35,
        ]);

        $active5m = 0;
        $visitors30m = 0;
        $pageviews30m = 0;
        $minMap = [];
        foreach ($rtMinRes->json('rows') ?? [] as $row) {
            $minsAgo = (int) ($row['dimensionValues'][0]['value'] ?? 30);
            $u = (int) ($row['metricValues'][0]['value'] ?? 0);
            $pv = (int) ($row['metricValues'][1]['value'] ?? 0);
            $minMap[$minsAgo] = ['visitors' => $u, 'pageviews' => $pv];
            $pageviews30m += $pv;
            if ($minsAgo <= 4) {
                $active5m += $u;
            }
        }

        $rtCountries = [];
        $rtCities = [];
        $rtPages = [];
        $rtDevices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
        $liveFeed = [];

        foreach ($rtGeoRes->json('rows') ?? [] as $idx => $row) {
            $dims = $row['dimensionValues'] ?? [];
            $mets = $row['metricValues'] ?? [];
            $cName = (string) ($dims[0]['value'] ?? 'Unknown');
            $cc = strtoupper((string) ($dims[1]['value'] ?? 'UN'));
            if (strlen($cc) !== 2) {
                $cc = 'UN';
            }
            $city = (string) ($dims[2]['value'] ?? 'Unknown');
            if ($city === '(not set)') {
                $city = 'Unknown City';
            }
            $screen = (string) ($dims[3]['value'] ?? '/');
            $dev = strtolower((string) ($dims[4]['value'] ?? 'desktop'));
            $u = (int) ($mets[0]['value'] ?? 0);
            $pv = (int) ($mets[1]['value'] ?? 0);

            $visitors30m += $u;
            if (isset($rtDevices[$dev])) {
                $rtDevices[$dev] += $u;
            }
            $rtCountries[$cc] = ($rtCountries[$cc] ?? 0) + $u;
            $rtCities["{$cc}|{$city}"] = [
                'country_code' => $cc,
                'country_name' => $cName,
                'region' => $cName,
                'city' => $city,
                'visitors' => (($rtCities["{$cc}|{$city}"]['visitors'] ?? 0) + $u),
                'active_now' => (($rtCities["{$cc}|{$city}"]['active_now'] ?? 0) + $u),
                'pageviews' => (($rtCities["{$cc}|{$city}"]['pageviews'] ?? 0) + $pv),
            ];
            $rtPages[$screen] = [
                'path' => $screen,
                'page_title' => $screen,
                'visitors' => (($rtPages[$screen]['visitors'] ?? 0) + $u),
                'active_now' => (($rtPages[$screen]['active_now'] ?? 0) + $u),
                'pageviews' => (($rtPages[$screen]['pageviews'] ?? 0) + $pv),
            ];

            if (count($liveFeed) < 25) {
                $liveFeed[] = [
                    'id' => 'ga4_' . $idx,
                    'path' => $screen,
                    'page_title' => $screen,
                    'country_code' => $cc,
                    'country_name' => $cName,
                    'region' => null,
                    'city' => $city,
                    'referrer_domain' => 'GA4 Realtime',
                    'device_type' => $dev,
                    'browser' => 'GA4',
                    'os' => '',
                    'occurred_at' => now()->toIso8601String(),
                    'seconds_ago' => 15,
                    'is_active' => true,
                ];
            }
        }

        $activeNow = $active5m > 0 ? $active5m : $visitors30m;

        // 3. Historical Report for exact Country + Region + City + Pages + Sources + Timeline (when range != 30m)
        $startDate = match ($range) {
            '7d' => '7daysAgo',
            '30d' => '30daysAgo',
            default => 'yesterday',
        };

        $byCountry = [];
        $byRegion = [];
        $topPages = [];
        $topReferrers = [];
        $histDevices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
        $timeline = [];
        $uniqueVisitorsRange = $visitors30m;
        $pageviewsRange = $pageviews30m;

        if ($range !== '30m') {
            $geoHistRes = Http::withToken($accessToken)->timeout(12)->post($histUrl, [
                'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
                'dimensions' => [
                    ['name' => 'country'],
                    ['name' => 'countryId'],
                    ['name' => 'region'],
                    ['name' => 'city'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                    ['name' => 'screenPageViews'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
                'limit' => 150,
            ]);

            if ($geoHistRes->successful()) {
                $cMap = [];
                $regRows = [];
                $totalU = 0;
                $totalPv = 0;
                $countryFilterUpper = $countryFilter ? strtoupper(trim($countryFilter)) : null;

                foreach ($geoHistRes->json('rows') ?? [] as $row) {
                    $d = $row['dimensionValues'] ?? [];
                    $m = $row['metricValues'] ?? [];
                    $cName = (string) ($d[0]['value'] ?? 'Unknown');
                    $cc = strtoupper((string) ($d[1]['value'] ?? 'UN'));
                    if (strlen($cc) !== 2) {
                        $cc = 'UN';
                    }
                    $reg = (string) ($d[2]['value'] ?? 'Unknown Region');
                    $city = (string) ($d[3]['value'] ?? 'Unknown City');
                    if ($reg === '(not set)') {
                        $reg = 'Unknown Region';
                    }
                    if ($city === '(not set)') {
                        $city = 'Unknown City';
                    }
                    $u = (int) ($m[0]['value'] ?? 0);
                    $pv = (int) ($m[1]['value'] ?? 0);

                    $totalU += $u;
                    $totalPv += $pv;

                    if (!isset($cMap[$cc])) {
                        $cMap[$cc] = [
                            'country_code' => $cc,
                            'country_name' => $cName !== '(not set)' ? $cName : CountryCatalog::name($cc),
                            'visitors' => 0,
                            'active_now' => $rtCountries[$cc] ?? 0,
                            'pageviews' => 0,
                            'regions' => [],
                        ];
                    }
                    $cMap[$cc]['visitors'] += $u;
                    $cMap[$cc]['pageviews'] += $pv;
                    if ($reg !== 'Unknown Region') {
                        $cMap[$cc]['regions'][$reg] = ($cMap[$cc]['regions'][$reg] ?? 0) + $u;
                    }

                    if (!$countryFilterUpper || $cc === $countryFilterUpper) {
                        $regRows[] = [
                            'country_code' => $cc,
                            'country_name' => $cMap[$cc]['country_name'],
                            'region' => $reg,
                            'city' => $city,
                            'visitors' => $u,
                            'active_now' => $rtCities["{$cc}|{$city}"]['active_now'] ?? 0,
                            'pageviews' => $pv,
                        ];
                    }
                }

                $uniqueVisitorsRange = max($totalU, $visitors30m);
                $pageviewsRange = max($totalPv, $pageviews30m);
                $denom = max(1, $totalU);

                foreach ($cMap as $row) {
                    arsort($row['regions']);
                    $byCountry[] = [
                        'country_code' => $row['country_code'],
                        'country_name' => $row['country_name'],
                        'visitors' => $row['visitors'],
                        'active_now' => $row['active_now'],
                        'pageviews' => $row['pageviews'],
                        'share_pct' => round(($row['visitors'] / $denom) * 100, 1),
                        'top_regions' => array_slice(array_keys($row['regions']), 0, 3),
                    ];
                }
                usort($byCountry, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);
                $byRegion = array_slice($regRows, 0, 30);
            }

            // Historical Timeline (hourly for 24h, daily for 7d/30d)
            $timeDim = $range === '24h' ? 'dateHour' : 'date';
            $tlRes = Http::withToken($accessToken)->timeout(10)->post($histUrl, [
                'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
                'dimensions' => [['name' => $timeDim]],
                'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
                'orderBys' => [['dimension' => ['dimensionName' => $timeDim], 'desc' => false]],
                'limit' => 48,
            ]);
            foreach ($tlRes->json('rows') ?? [] as $row) {
                $rawD = (string) ($row['dimensionValues'][0]['value'] ?? '');
                $label = $rawD;
                if ($timeDim === 'dateHour' && strlen($rawD) === 10) {
                    $label = substr($rawD, 8, 2) . ':00';
                } elseif ($timeDim === 'date' && strlen($rawD) === 8) {
                    $label = substr($rawD, 4, 2) . '/' . substr($rawD, 6, 2);
                }
                $timeline[] = [
                    'label' => $label,
                    'visitors' => (int) ($row['metricValues'][0]['value'] ?? 0),
                    'pageviews' => (int) ($row['metricValues'][1]['value'] ?? 0),
                ];
            }
            if ($timeDim === 'dateHour' && count($timeline) > 24) {
                $timeline = array_slice($timeline, -24);
            }

            // Pages, Sources, and Devices from GA4 Historical
            $pageRes = Http::withToken($accessToken)->timeout(10)->post($histUrl, [
                'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
                'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
                'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => 15,
            ]);
            foreach ($pageRes->json('rows') ?? [] as $row) {
                $topPages[] = [
                    'path' => (string) ($row['dimensionValues'][0]['value'] ?? '/'),
                    'page_title' => (string) ($row['dimensionValues'][1]['value'] ?? ''),
                    'visitors' => (int) ($row['metricValues'][0]['value'] ?? 0),
                    'active_now' => 0,
                    'pageviews' => (int) ($row['metricValues'][1]['value'] ?? 0),
                ];
            }

            $srcRes = Http::withToken($accessToken)->timeout(10)->post($histUrl, [
                'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
                'dimensions' => [['name' => 'sessionSource']],
                'metrics' => [['name' => 'activeUsers'], ['name' => 'screenPageViews']],
                'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
                'limit' => 12,
            ]);
            foreach ($srcRes->json('rows') ?? [] as $row) {
                $src = (string) ($row['dimensionValues'][0]['value'] ?? '(direct)');
                $topReferrers[] = [
                    'source' => $src === '(direct)' ? 'Direct' : $src,
                    'visitors' => (int) ($row['metricValues'][0]['value'] ?? 0),
                    'pageviews' => (int) ($row['metricValues'][1]['value'] ?? 0),
                ];
            }

            $devRes = Http::withToken($accessToken)->timeout(10)->post($histUrl, [
                'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [['name' => 'activeUsers']],
            ]);
            foreach ($devRes->json('rows') ?? [] as $row) {
                $dk = strtolower((string) ($row['dimensionValues'][0]['value'] ?? 'desktop'));
                if (isset($histDevices[$dk])) {
                    $histDevices[$dk] += (int) ($row['metricValues'][0]['value'] ?? 0);
                }
            }
        }

        // Fallbacks when range == 30m or historical timeline was empty
        if (empty($timeline)) {
            for ($i = 29; $i >= 0; $i--) {
                $dt = now()->subMinutes($i);
                $timeline[] = [
                    'label' => $dt->format('H:i'),
                    'pageviews' => $minMap[$i]['pageviews'] ?? 0,
                    'visitors' => $minMap[$i]['visitors'] ?? 0,
                ];
            }
        }
        if (empty($byCountry) && !empty($rtCountries)) {
            $denom = max(1, array_sum($rtCountries));
            foreach ($rtCountries as $cc => $u) {
                $byCountry[] = [
                    'country_code' => $cc,
                    'country_name' => CountryCatalog::name($cc),
                    'visitors' => $u,
                    'active_now' => $u,
                    'pageviews' => $u,
                    'share_pct' => round(($u / $denom) * 100, 1),
                    'top_regions' => [],
                ];
            }
            usort($byCountry, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);
        }
        if (empty($byRegion)) {
            $byRegion = array_values($rtCities);
        }
        if (empty($topPages)) {
            $topPages = array_values($rtPages);
        }

        $finalDevices = array_sum($histDevices) > 0 ? $histDevices : $rtDevices;
        $devTotal = max(1, array_sum($finalDevices));
        $devicesOut = [];
        foreach ($finalDevices as $dev => $cnt) {
            $devicesOut[] = [
                'device' => $dev,
                'visitors' => $cnt,
                'share_pct' => round(($cnt / $devTotal) * 100, 1),
            ];
        }

        $integration->update(['last_synced_at' => now(), 'last_error' => null]);

        return [
            'available' => true,
            'data_source' => 'google_analytics_4',
            'property_id' => $propertyId,
            'property_name' => $meta['selected_property_name'] ?? "Property {$propertyId}",
            'range' => $range,
            'country_filter' => $countryFilter ? strtoupper($countryFilter) : null,
            'summary' => [
                'active_visitors_now' => $activeNow,
                'active_pages_now' => count($rtPages),
                'pageviews_30m' => $pageviews30m,
                'visitors_30m' => $visitors30m,
                'pageviews_range' => $pageviewsRange,
                'unique_visitors_range' => $uniqueVisitorsRange,
                'countries_count' => count($byCountry),
                'top_country' => $byCountry[0] ?? null,
            ],
            'timeline' => $timeline,
            'by_country' => array_slice($byCountry, 0, 30),
            'by_region' => array_slice($byRegion, 0, 30),
            'top_pages' => array_slice($topPages, 0, 15),
            'top_referrers' => array_slice($topReferrers, 0, 12),
            'devices' => $devicesOut,
            'browsers' => [],
            'live_feed' => $liveFeed,
        ];
    }

    private function extractEnableUrl(string $errorMessage, string $fallbackUrl): ?string
    {
        if (preg_match('#https://console\.(?:developers|cloud)\.google\.com/[^\s"\')]+#i', $errorMessage, $m)) {
            return rtrim($m[0], '.,;');
        }
        if (str_contains(strtolower($errorMessage), 'not been used') || str_contains(strtolower($errorMessage), 'is disabled')) {
            return $fallbackUrl;
        }
        return null;
    }

    private function getValidAccessToken(WebsiteIntegration $integration): string
    {
        $creds = $integration->getDecryptedCredentials() ?? [];
        $accessToken = (string) ($creds['access_token'] ?? '');
        $refreshToken = (string) ($creds['refresh_token'] ?? '');
        $expiresAt = (int) ($creds['expires_at'] ?? 0);

        if ($accessToken !== '' && ($expiresAt === 0 || $expiresAt > now()->addMinutes(2)->timestamp)) {
            return $accessToken;
        }

        if ($refreshToken === '' || !$this->isLiveConfigured()) {
            if ($accessToken !== '') {
                return $accessToken;
            }
            throw new Exception('Missing refresh token.');
        }

        $res = Http::asForm()->timeout(12)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (!$res->successful() || !$res->json('access_token')) {
            Log::warning('GA4 token refresh failed', ['status' => $res->status(), 'body' => $res->body()]);
            throw new Exception('Could not refresh GA4 access token.');
        }

        $creds['access_token'] = $res->json('access_token');
        $creds['expires_at'] = now()->addSeconds((int) ($res->json('expires_in') ?? 3600))->timestamp;
        $integration->update(['encrypted_credentials' => $creds]);

        return $creds['access_token'];
    }

    private function matchBestProperty(Website $website, array $properties): ?array
    {
        if (empty($properties)) {
            return null;
        }
        $domainStem = strtolower(preg_replace('/^www\./i', '', (string) $website->domain));
        $domainRoot = explode('.', $domainStem)[0] ?? $domainStem;
        $nameLower = strtolower((string) $website->name);

        foreach ($properties as $p) {
            $disp = strtolower((string) ($p['display_name'] ?? ''));
            $dispCompact = str_replace([' ', '-', '_'], '', $disp);
            if (
                $disp !== '' && (
                    str_contains($disp, $domainStem)
                    || str_contains($dispCompact, $domainRoot)
                    || str_contains($domainRoot, $dispCompact)
                    || ($nameLower !== '' && str_contains($disp, $nameLower))
                )
            ) {
                return $p;
            }
        }

        return $properties[0];
    }
}
