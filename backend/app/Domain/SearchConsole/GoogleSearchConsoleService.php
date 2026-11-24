<?php

namespace App\Domain\SearchConsole;

use App\Models\SearchConsoleSyncLog;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleSearchConsoleService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private array $scopes;
    private bool $sandboxFallback;

    public function __construct()
    {
        $this->clientId = trim((string) config('search_console.client_id', ''));
        $this->clientSecret = trim((string) config('search_console.client_secret', ''));
        $configuredRedirect = trim((string) config('search_console.redirect_uri', ''));
        $this->redirectUri = $configuredRedirect !== ''
            ? $configuredRedirect
            : url('/api/v1/integrations/google-search-console/callback');
        $this->scopes = config('search_console.scopes', ['https://www.googleapis.com/auth/webmasters.readonly']);
        $this->sandboxFallback = (bool) config('search_console.sandbox_fallback', true);
    }

    /**
     * Whether real Google OAuth 2.0 credentials are configured.
     */
    public function isLiveConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    /**
     * Return the active OAuth 2.0 callback redirect URI.
     */
    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Generate the OAuth 2.0 authorization URL.
     */
    public function getAuthorizationUrl(Website $website, int $userId): string
    {
        $statePayload = [
            'website_id' => $website->id,
            'organization_id' => $website->organization_id,
            'user_id' => $userId,
            'timestamp' => now()->timestamp,
        ];
        $state = base64_encode(json_encode($statePayload));

        if (!$this->isLiveConfigured()) {
            // If OAuth credentials are not set, return sandbox authorization URL
            return url('/api/v1/integrations/google-search-console/sandbox-callback') . '?' . http_build_query([
                'state' => $state,
                'code' => 'sandbox_gsc_auth_code',
            ]);
        }

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            // Request ONLY the Search Console read scope so Google presents a clean single-permission
            // consent prompt instead of multi-checkbox granular permissions users often leave unchecked.
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $params;
    }

    /**
     * Handle OAuth callback and securely exchange authorization code for tokens.
     */
    public function handleCallback(string $code, string $state): WebsiteIntegration
    {
        $decodedState = json_decode(base64_decode($state), true);
        if (!$decodedState || !isset($decodedState['website_id'], $decodedState['organization_id'])) {
            throw new Exception('Invalid OAuth state parameter.');
        }

        $website = Website::where('id', $decodedState['website_id'])
            ->where('organization_id', $decodedState['organization_id'])
            ->firstOrFail();

        // Check if code is sandbox
        if (str_starts_with($code, 'sandbox_') || !$this->isLiveConfigured()) {
            return $this->connectSandbox($website);
        }

        // Live Google OAuth Token Exchange
        $response = Http::asForm()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri,
            ]);

        if (!$response->successful()) {
            $errorMsg = $response->json('error_description') ?? $response->json('error') ?? 'Failed to exchange authorization code.';
            Log::error('Google Search Console OAuth token exchange error', ['response' => $response->body()]);
            throw new Exception("Google OAuth failed: {$errorMsg}");
        }

        $tokenData = $response->json();
        $accessToken = $tokenData['access_token'] ?? null;
        $refreshToken = $tokenData['refresh_token'] ?? null;
        $expiresIn = $tokenData['expires_in'] ?? 3600;
        $grantedScope = trim((string) ($tokenData['scope'] ?? ''));
        $oauthIntent = (string) ($decodedState['intent'] ?? 'gsc');

        if (!$accessToken) {
            throw new Exception('No access token returned from Google.');
        }

        // Guard against Google's Granular Permissions screen where users click Continue without
        // checking the required Search Console / Analytics permission checkbox.
        $hasWebmastersScope = $grantedScope === '' || str_contains($grantedScope, 'webmasters');
        $hasAnalyticsScope = $grantedScope === '' || str_contains($grantedScope, 'analytics.readonly');

        if ($oauthIntent === 'ga4' && !$hasAnalyticsScope) {
            throw new Exception(
                'Google Analytics permission was not granted. Please click Connect again and make sure to check "See and download your Google Analytics data" on Google\'s permission screen.'
            );
        }

        if ($oauthIntent !== 'ga4' && !$hasWebmastersScope) {
            throw new Exception(
                'Google Search Console permission was not granted. Please click "Connect Search Console" again and check the box "View Search Console data for your verified sites" before clicking Continue.'
            );
        }

        // Preserve existing refresh_token if Google omits it on re-authorization
        if (!$refreshToken) {
            $existing = WebsiteIntegration::where('website_id', $website->id)
                ->where('provider', WebsiteIntegration::PROVIDER_GSC)
                ->first();
            $existingCreds = $existing?->getDecryptedCredentials();
            if (!empty($existingCreds['refresh_token']) && empty($existingCreds['is_sandbox'])) {
                $refreshToken = $existingCreds['refresh_token'];
            }
        }

        $credentials = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($expiresIn)->timestamp,
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'scope' => $grantedScope,
            'is_sandbox' => false,
        ];

        $integration = null;

        // Only update/create GSC integration if webmasters scope was actually granted
        if ($hasWebmastersScope) {
            // Fetch user's verified properties to auto-select best match
            $properties = $this->fetchPropertiesFromGoogle($accessToken);
            $selectedProperty = $this->determineBestMatchingProperty($website, $properties);

            $integration = WebsiteIntegration::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'provider' => WebsiteIntegration::PROVIDER_GSC,
                ],
                [
                    'organization_id' => $website->organization_id,
                    'encrypted_credentials' => $credentials,
                    'status' => WebsiteIntegration::STATUS_CONNECTED,
                    'last_error' => null,
                    'metadata' => [
                        'account_connected' => true,
                        'is_sandbox' => false,
                        'connected_at' => now()->toIso8601String(),
                        'selected_property' => $selectedProperty,
                        'properties' => $properties,
                        'scope' => $grantedScope !== '' ? $grantedScope : 'https://www.googleapis.com/auth/webmasters.readonly',
                    ],
                ]
            );

            // Auto-verify website ownership when Google confirms a matching verified property
            if (!$website->isVerified() && $this->findMatchingVerifiedProperty($website, $properties) !== null) {
                $website->update([
                    'verification_status' => Website::VERIFICATION_VERIFIED,
                    'verification_method' => 'google_search_console',
                    'verified_at' => now(),
                ]);
            }
        }

        // Also store Google Analytics 4 integration automatically when analytics.readonly scope is granted (or intent=ga4)
        if ($oauthIntent === 'ga4' || str_contains($grantedScope, 'analytics.readonly')) {
            try {
                $ga4Integration = app(\App\Domain\Traffic\GoogleAnalyticsService::class)->storeFromTokens($website, $credentials);
                if (!$integration) {
                    $integration = $ga4Integration;
                }
            } catch (Throwable $gaEx) {
                Log::warning('GA4 auto-connect during Google OAuth callback skipped: ' . $gaEx->getMessage());
            }
        }

        if (!$integration) {
            throw new Exception('No valid Google Search Console or Analytics scope was granted.');
        }

        $this->invalidateCache($website->id);

        return $integration;
    }

    /**
     * Check if a connected GSC integration proves ownership of this website domain.
     * Returns the matched siteUrl or null if not verified in GSC.
     */
    public function findVerifiedPropertyForWebsite(Website $website): ?string
    {
        $integration = $website->gscIntegration;
        if (!$integration || $integration->status !== WebsiteIntegration::STATUS_CONNECTED) {
            return null;
        }

        $properties = $integration->metadata['properties'] ?? [];

        // If connected live and cached properties are empty, refresh directly from Google
        if (empty($properties) && empty($integration->metadata['is_sandbox'])) {
            try {
                $accessToken = $this->getValidAccessToken($integration);
                $properties = $this->fetchPropertiesFromGoogle($accessToken);
                if (!empty($properties)) {
                    $metadata = $integration->metadata ?? [];
                    $metadata['properties'] = $properties;
                    $integration->update(['metadata' => $metadata]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed refreshing GSC properties during ownership verification', [
                    'website_id' => $website->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->findMatchingVerifiedProperty($website, $properties);
    }

    /**
     * Match a website's domain against Google Search Console properties with owner/full permissions.
     */
    private function findMatchingVerifiedProperty(Website $website, array $properties): ?string
    {
        $allowedLevels = ['siteOwner', 'siteFullUser'];
        foreach ($properties as $prop) {
            $siteUrl = (string) ($prop['siteUrl'] ?? '');
            $level = (string) ($prop['permissionLevel'] ?? '');
            if ($siteUrl !== '' && in_array($level, $allowedLevels, true) && $website->matchesGscProperty($siteUrl)) {
                return $siteUrl;
            }
        }

        return null;
    }

    /**
     * Connect via realistic sandbox mode when credentials are not configured.
     */
    public function connectSandbox(Website $website): WebsiteIntegration
    {
        $domain = $website->domain;
        $domainProperty = "sc-domain:{$domain}";
        $urlProperty = "https://{$domain}/";

        $properties = [
            [
                'siteUrl' => $domainProperty,
                'permissionLevel' => 'siteOwner',
            ],
            [
                'siteUrl' => $urlProperty,
                'permissionLevel' => 'siteOwner',
            ],
        ];

        $credentials = [
            'access_token' => 'sandbox_access_token_' . md5($website->id . now()->toIso8601String()),
            'refresh_token' => 'sandbox_refresh_token_' . md5($website->domain),
            'expires_at' => now()->addDays(30)->timestamp,
            'token_type' => 'Bearer',
            'scope' => implode(' ', $this->scopes),
            'is_sandbox' => true,
        ];

        return WebsiteIntegration::updateOrCreate(
            [
                'website_id' => $website->id,
                'provider' => WebsiteIntegration::PROVIDER_GSC,
            ],
            [
                'organization_id' => $website->organization_id,
                'encrypted_credentials' => $credentials,
                'status' => WebsiteIntegration::STATUS_CONNECTED,
                'last_error' => null,
                'metadata' => [
                    'account_connected' => true,
                    'is_sandbox' => true,
                    'connected_at' => now()->toIso8601String(),
                    'selected_property' => $domainProperty,
                    'properties' => $properties,
                    'scope' => implode(' ', $this->scopes),
                ],
            ]
        );
    }

    /**
     * Disconnect Google Search Console integration.
     */
    public function disconnect(Website $website): bool
    {
        $integration = $website->gscIntegration;
        if (!$integration) {
            return false;
        }

        $credentials = $integration->getDecryptedCredentials();
        if ($credentials && !empty($credentials['access_token']) && empty($credentials['is_sandbox'])) {
            try {
                Http::asForm()->post('https://oauth2.googleapis.com/revoke', [
                    'token' => $credentials['access_token'],
                ]);
            } catch (Throwable $e) {
                Log::warning('Could not revoke Google OAuth token', ['error' => $e->getMessage()]);
            }
        }

        $integration->update([
            'status' => WebsiteIntegration::STATUS_DISCONNECTED,
            'encrypted_credentials' => null,
            'last_error' => null,
            'metadata' => array_merge($integration->metadata ?? [], [
                'account_connected' => false,
                'disconnected_at' => now()->toIso8601String(),
            ]),
        ]);

        return true;
    }

    /**
     * Get a valid access token, automatically refreshing if expired or expiring soon.
     */
    public function getValidAccessToken(WebsiteIntegration $integration): string
    {
        $credentials = $integration->getDecryptedCredentials();
        if (!$credentials || empty($credentials['access_token'])) {
            throw new Exception('No active credentials found for Google Search Console.');
        }

        // Sandbox check
        if (!empty($credentials['is_sandbox'])) {
            return $credentials['access_token'];
        }

        $expiresAt = $credentials['expires_at'] ?? 0;
        // If token expires in less than 5 minutes, refresh it
        if (now()->timestamp >= ($expiresAt - 300)) {
            $refreshToken = $credentials['refresh_token'] ?? null;
            if (!$refreshToken) {
                $integration->update([
                    'status' => WebsiteIntegration::STATUS_FAILED,
                    'last_error' => 'Refresh token missing. Reconnection required.',
                ]);
                throw new Exception('Google Search Console token expired and refresh token is missing. Please reconnect.');
            }

            $response = Http::asForm()
                ->timeout(15)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);

            if (!$response->successful()) {
                $integration->update([
                    'status' => WebsiteIntegration::STATUS_FAILED,
                    'last_error' => 'Failed to refresh token: ' . ($response->json('error_description') ?? 'Access revoked.'),
                ]);
                throw new Exception('Failed to refresh Google Search Console access token. Please reconnect.');
            }

            $refreshed = $response->json();
            $newAccessToken = $refreshed['access_token'];
            $newExpiresIn = $refreshed['expires_in'] ?? 3600;

            $credentials['access_token'] = $newAccessToken;
            $credentials['expires_at'] = now()->addSeconds($newExpiresIn)->timestamp;

            $integration->update([
                'encrypted_credentials' => $credentials,
                'status' => WebsiteIntegration::STATUS_CONNECTED,
                'last_error' => null,
            ]);

            return $newAccessToken;
        }

        return $credentials['access_token'];
    }

    /**
     * List verified properties from Search Console.
     */
    public function listProperties(WebsiteIntegration $integration): array
    {
        $credentials = $integration->getDecryptedCredentials();
        if (!empty($credentials['is_sandbox'])) {
            return $integration->metadata['properties'] ?? [];
        }

        $accessToken = $this->getValidAccessToken($integration);
        return $this->fetchPropertiesFromGoogle($accessToken);
    }

    /**
     * Select active property.
     */
    public function selectProperty(WebsiteIntegration $integration, string $propertyId): WebsiteIntegration
    {
        $metadata = $integration->metadata ?? [];
        $metadata['selected_property'] = $propertyId;
        unset($metadata['exact_snapshots']);

        $integration->update([
            'metadata' => $metadata,
        ]);

        $this->invalidateCache($integration->website_id);

        return $integration;
    }

    /**
     * Bust cached live GSC performance queries for a website.
     */
    public function invalidateCache(int $websiteId): void
    {
        $verKey = "gsc_cache_ver:{$websiteId}";
        $current = (int) Cache::get($verKey, 1);
        Cache::put($verKey, $current + 1, now()->addDays(30));
    }

    /**
     * Fetch live, exact Google Search Console performance analytics matching the official GSC UI.
     *
     * Uses un-truncated dimension queries (dimensions: [] for exact totals, ['date'] for daily trend,
     * and separate ['query'], ['page'], ['device'], ['country'] queries) so anonymized long-tail queries
     * are NEVER dropped from site totals or daily charts.
     *
     * Returns null when GSC is disconnected or running in sandbox mode.
     */
    public function fetchLivePerformanceReport(
        Website $website,
        string $range = '28d',
        ?string $customStart = null,
        ?string $customEnd = null,
        array $filters = [],
        bool $forceRefresh = false
    ): ?array {
        $integration = $website->gscIntegration;
        if (!$integration || $integration->status !== WebsiteIntegration::STATUS_CONNECTED) {
            return null;
        }

        $credentials = $integration->getDecryptedCredentials();
        if (!$credentials || !empty($credentials['is_sandbox'])) {
            return null;
        }

        $propertyId = $integration->metadata['selected_property'] ?? "sc-domain:{$website->domain}";
        $cacheVer = (int) Cache::get("gsc_cache_ver:{$website->id}", 1);
        $normalizedFilters = [
            'country' => !empty($filters['country']) ? strtolower(trim((string) $filters['country'])) : null,
            'device' => !empty($filters['device']) ? strtoupper(trim((string) $filters['device'])) : null,
            'query' => !empty($filters['query']) ? trim((string) $filters['query']) : null,
            'page' => !empty($filters['page']) ? trim((string) $filters['page']) : null,
        ];
        $hasFilters = !empty(array_filter($normalizedFilters, fn ($v) => $v !== null && $v !== ''));

        $cacheKey = sprintf(
            'gsc_live_perf:v%d:%d:%s:%s:%s:%s:%s',
            $cacheVer,
            $website->id,
            md5($propertyId),
            $range,
            $customStart ?? '',
            $customEnd ?? '',
            md5(json_encode($normalizedFilters))
        );

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $accessToken = $this->getValidAccessToken($integration);
        $encodedSiteUrl = rawurlencode($propertyId);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        // 1. Align end date to Google Search Console's exact latest finalized date (typically 2-3 days ago)
        if ($range === 'custom' && $customEnd) {
            $endDate = Carbon::parse($customEnd);
        } elseif ($customEnd) {
            $endDate = Carbon::parse($customEnd);
        } else {
            $endDate = Carbon::parse($this->resolveLatestAvailableDate($accessToken, $propertyId, $website->id, $cacheVer));
        }

        $startDate = match ($range) {
            '7d' => (clone $endDate)->subDays(6),
            '3m', '90d' => (clone $endDate)->subDays(89),
            'custom' => $customStart ? Carbon::parse($customStart) : (clone $endDate)->subDays(27),
            default => (clone $endDate)->subDays(27), // Exact 28 full days matching GSC UI
        };

        $days = max(1, $startDate->diffInDays($endDate) + 1);
        $prevEndDate = (clone $startDate)->subDays(1);
        $prevStartDate = (clone $prevEndDate)->subDays($days - 1);

        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();
        $prevStartStr = $prevStartDate->toDateString();
        $prevEndStr = $prevEndDate->toDateString();

        // 2. Build GSC dimensionFilterGroups for any active filters
        $dimensionFilterGroups = $this->buildDimensionFilterGroups($normalizedFilters);

        $makePayload = function (string $from, string $to, array $dimensions = [], int $rowLimit = 100) use ($dimensionFilterGroups): array {
            $payload = [
                'startDate' => $from,
                'endDate' => $to,
                'type' => 'web',
                'rowLimit' => $rowLimit,
            ];
            if (!empty($dimensions)) {
                $payload['dimensions'] = $dimensions;
            }
            if (!empty($dimensionFilterGroups)) {
                $payload['dimensionFilterGroups'] = $dimensionFilterGroups;
            }
            return $payload;
        };

        // 3. Execute all 7 dimension queries in parallel (eliminates anonymized query truncation!)
        $responses = Http::pool(fn (\Illuminate\Http\Client\Pool $pool) => [
            'curr_summary' => $pool->as('curr_summary')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, [], 1)),
            'prev_summary' => $pool->as('prev_summary')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($prevStartStr, $prevEndStr, [], 1)),
            'daily'        => $pool->as('daily')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, ['date'], 500)),
            'queries'      => $pool->as('queries')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, ['query'], 100)),
            'pages'        => $pool->as('pages')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, ['page'], 100)),
            'devices'      => $pool->as('devices')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, ['device'], 10)),
            'countries'    => $pool->as('countries')->withToken($accessToken)->timeout(20)->post($endpoint, $makePayload($startStr, $endStr, ['country'], 50)),
        ]);

        // Verify at least summary or daily succeeded; throw if auth/API failed so caller can handle/fallback
        $currResp = $responses['curr_summary'] ?? null;
        $dailyResp = $responses['daily'] ?? null;
        if (
            (!$currResp || $currResp instanceof Throwable || !$currResp->successful()) &&
            (!$dailyResp || $dailyResp instanceof Throwable || !$dailyResp->successful())
        ) {
            $errMsg = ($currResp && !($currResp instanceof Throwable))
                ? ($currResp->json('error.message') ?? $currResp->body())
                : ($currResp instanceof Throwable ? $currResp->getMessage() : 'GSC live query failed');
            throw new Exception('Google Search Console live query failed: ' . $errMsg);
        }

        $extractRows = function ($resp): array {
            if (!$resp || $resp instanceof Throwable || !$resp->successful()) {
                return [];
            }
            return $resp->json('rows') ?? [];
        };

        // 4. Daily timeseries (gap-filled across the exact window so chart is continuous)
        $dailyByDate = [];
        foreach ($extractRows($dailyResp) as $row) {
            $d = (string) ($row['keys'][0] ?? '');
            if ($d === '') {
                continue;
            }
            $clicks = (int) round((float) ($row['clicks'] ?? 0));
            $impressions = (int) round((float) ($row['impressions'] ?? 0));
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
            $pos = round((float) ($row['position'] ?? 0), 1);

            $dailyByDate[$d] = [
                'date' => $d,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'position' => $pos,
            ];
        }

        $timeseries = [];
        $cursor = (clone $startDate);
        while ($cursor->lte($endDate)) {
            $dStr = $cursor->toDateString();
            $timeseries[] = $dailyByDate[$dStr] ?? [
                'date' => $dStr,
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => 0.0,
                'position' => 0.0,
            ];
            $cursor->addDay();
        }

        // 5. Exact current & prior summary (from dimensions: [] — matches GSC UI KPI cards 1-to-1)
        $currRow = $extractRows($currResp)[0] ?? null;
        if ($currRow) {
            $currClicks = (int) round((float) ($currRow['clicks'] ?? 0));
            $currImp = (int) round((float) ($currRow['impressions'] ?? 0));
            $currCtr = $currImp > 0 ? round(($currClicks / $currImp) * 100, 2) : 0.0;
            $currPos = $currImp > 0 ? round((float) ($currRow['position'] ?? 0), 1) : 0.0;
        } else {
            // Fallback to summing exact daily rows with impression-weighted position
            $currClicks = array_sum(array_column($timeseries, 'clicks'));
            $currImp = array_sum(array_column($timeseries, 'impressions'));
            $currCtr = $currImp > 0 ? round(($currClicks / $currImp) * 100, 2) : 0.0;
            $weightedPos = 0.0;
            foreach ($timeseries as $pt) {
                $weightedPos += $pt['position'] * $pt['impressions'];
            }
            $currPos = $currImp > 0 ? round($weightedPos / $currImp, 1) : 0.0;
        }

        $prevRow = $extractRows($responses['prev_summary'] ?? null)[0] ?? null;
        $prevClicks = $prevRow ? (int) round((float) ($prevRow['clicks'] ?? 0)) : 0;
        $prevImp = $prevRow ? (int) round((float) ($prevRow['impressions'] ?? 0)) : 0;
        $prevCtr = $prevImp > 0 ? round(($prevClicks / $prevImp) * 100, 2) : 0.0;
        $prevPos = ($prevRow && $prevImp > 0) ? round((float) ($prevRow['position'] ?? 0), 1) : 0.0;

        // 6. Top Queries, Pages, Devices, Countries (exact dimension-level rows from GSC)
        $queries = array_map(function ($row) {
            $clicks = (int) round((float) ($row['clicks'] ?? 0));
            $imp = (int) round((float) ($row['impressions'] ?? 0));
            return [
                'query' => (string) ($row['keys'][0] ?? ''),
                'clicks' => $clicks,
                'impressions' => $imp,
                'ctr' => $imp > 0 ? round(($clicks / $imp) * 100, 2) : 0.0,
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }, $extractRows($responses['queries'] ?? null));

        $pages = array_map(function ($row) {
            $clicks = (int) round((float) ($row['clicks'] ?? 0));
            $imp = (int) round((float) ($row['impressions'] ?? 0));
            return [
                'page' => (string) ($row['keys'][0] ?? ''),
                'clicks' => $clicks,
                'impressions' => $imp,
                'ctr' => $imp > 0 ? round(($clicks / $imp) * 100, 2) : 0.0,
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }, $extractRows($responses['pages'] ?? null));

        $devices = array_map(function ($row) {
            $clicks = (int) round((float) ($row['clicks'] ?? 0));
            $imp = (int) round((float) ($row['impressions'] ?? 0));
            return [
                'device' => strtoupper((string) ($row['keys'][0] ?? 'DESKTOP')),
                'clicks' => $clicks,
                'impressions' => $imp,
                'ctr' => $imp > 0 ? round(($clicks / $imp) * 100, 2) : 0.0,
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }, $extractRows($responses['devices'] ?? null));

        $countries = array_map(function ($row) {
            $clicks = (int) round((float) ($row['clicks'] ?? 0));
            $imp = (int) round((float) ($row['impressions'] ?? 0));
            return [
                'country' => strtoupper((string) ($row['keys'][0] ?? 'USA')),
                'clicks' => $clicks,
                'impressions' => $imp,
                'ctr' => $imp > 0 ? round(($clicks / $imp) * 100, 2) : 0.0,
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }, $extractRows($responses['countries'] ?? null));

        $queryAttributedClicks = array_sum(array_column($queries, 'clicks'));
        $anonymizedClicks = max(0, $currClicks - $queryAttributedClicks);

        $result = [
            'data_source' => 'google_search_console_live',
            'property_id' => $propertyId,
            'period' => [
                'start_date' => $startStr,
                'end_date' => $endStr,
                'previous_start_date' => $prevStartStr,
                'previous_end_date' => $prevEndStr,
            ],
            'summary' => [
                'clicks' => $currClicks,
                'impressions' => $currImp,
                'ctr' => $currCtr,
                'position' => $currPos,
                'anonymized_query_clicks' => $anonymizedClicks,
                'comparison' => [
                    'previous_clicks' => $prevClicks,
                    'previous_impressions' => $prevImp,
                    'previous_ctr' => $prevCtr,
                    'previous_position' => $prevPos,
                    'diff_clicks' => $currClicks - $prevClicks,
                    'diff_impressions' => $currImp - $prevImp,
                    'diff_ctr' => round($currCtr - $prevCtr, 2),
                    'diff_position' => round($currPos - $prevPos, 1),
                ],
            ],
            'timeseries' => $timeseries,
            'queries' => $queries,
            'pages' => $pages,
            'devices' => $devices,
            'countries' => $countries,
        ];

        // Persist exact unfiltered snapshot on integration metadata for Reports & Overview fallbacks
        if (!$hasFilters && in_array($range, ['7d', '28d', '3m', '90d'], true)) {
            $snapKey = $range === '3m' ? '90d' : $range;
            $metadata = $integration->metadata ?? [];
            $metadata['exact_snapshots'][$snapKey] = [
                'property_id' => $propertyId,
                'start_date' => $startStr,
                'end_date' => $endStr,
                'clicks' => $currClicks,
                'impressions' => $currImp,
                'ctr' => $currCtr,
                'position' => $currPos,
                'captured_at' => now()->toIso8601String(),
            ];
            $integration->update(['metadata' => $metadata]);
        }

        Cache::put($cacheKey, $result, now()->addMinutes(10));

        return $result;
    }

    /**
     * Detect Google Search Console's exact latest finalized date (typically 2-3 days behind today).
     */
    private function resolveLatestAvailableDate(string $accessToken, string $propertyId, int $websiteId, int $cacheVer): string
    {
        $anchorKey = sprintf('gsc_latest_date:v%d:%d:%s', $cacheVer, $websiteId, md5($propertyId));
        $cached = Cache::get($anchorKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $encodedSiteUrl = rawurlencode($propertyId);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        try {
            $resp = Http::withToken($accessToken)
                ->timeout(12)
                ->post($endpoint, [
                    'startDate' => now()->subDays(14)->toDateString(),
                    'endDate' => now()->toDateString(),
                    'dimensions' => ['date'],
                    'type' => 'web',
                    'rowLimit' => 20,
                ]);

            if ($resp->successful()) {
                $rows = $resp->json('rows') ?? [];
                $dates = array_filter(array_map(fn ($r) => (string) ($r['keys'][0] ?? ''), $rows));
                if (!empty($dates)) {
                    rsort($dates);
                    $latest = $dates[0];
                    Cache::put($anchorKey, $latest, now()->addMinutes(15));
                    return $latest;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Could not detect latest GSC finalized date, falling back to 2 days ago', [
                'website_id' => $websiteId,
                'error' => $e->getMessage(),
            ]);
        }

        return now()->subDays(2)->toDateString();
    }

    /**
     * Build official GSC API dimensionFilterGroups from user filters.
     */
    private function buildDimensionFilterGroups(array $filters): array
    {
        $items = [];
        if (!empty($filters['country'])) {
            $items[] = [
                'dimension' => 'country',
                'operator' => 'equals',
                'expression' => strtolower((string) $filters['country']),
            ];
        }
        if (!empty($filters['device'])) {
            $items[] = [
                'dimension' => 'device',
                'operator' => 'equals',
                'expression' => strtoupper((string) $filters['device']),
            ];
        }
        if (!empty($filters['query'])) {
            $items[] = [
                'dimension' => 'query',
                'operator' => 'contains',
                'expression' => (string) $filters['query'],
            ];
        }
        if (!empty($filters['page'])) {
            $items[] = [
                'dimension' => 'page',
                'operator' => 'contains',
                'expression' => (string) $filters['page'],
            ];
        }

        if (empty($items)) {
            return [];
        }

        return [
            [
                'groupType' => 'and',
                'filters' => $items,
            ],
        ];
    }

    /**
     * Synchronize search analytics data from Google Search Console.
     */
    public function syncSearchAnalytics(Website $website, ?string $startDate = null, ?string $endDate = null): SearchConsoleSyncLog
    {
        $integration = $website->gscIntegration;
        if (!$integration || $integration->status !== WebsiteIntegration::STATUS_CONNECTED) {
            throw new Exception('Google Search Console is not connected for this website.');
        }

        $propertyId = $integration->metadata['selected_property'] ?? "sc-domain:{$website->domain}";

        $credentials = $integration->getDecryptedCredentials();
        $isSandbox = !empty($credentials['is_sandbox']);

        // Bust live cache immediately so fresh data is pulled on next read
        $this->invalidateCache($website->id);

        if ($isSandbox) {
            $end = $endDate ? Carbon::parse($endDate)->toDateString() : now()->subDays(1)->toDateString();
            $start = $startDate ? Carbon::parse($startDate)->toDateString() : Carbon::parse($end)->subDays(27)->toDateString();
        } else {
            $accessToken = $this->getValidAccessToken($integration);
            $cacheVer = (int) Cache::get("gsc_cache_ver:{$website->id}", 1);
            $end = $endDate
                ? Carbon::parse($endDate)->toDateString()
                : $this->resolveLatestAvailableDate($accessToken, $propertyId, $website->id, $cacheVer);
            // Sync last 60 days by default so 28d + prior 28d opportunity comparisons have full history
            $start = $startDate
                ? Carbon::parse($startDate)->toDateString()
                : Carbon::parse($end)->subDays(59)->toDateString();
        }

        $syncLog = SearchConsoleSyncLog::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'property_id' => $propertyId,
            'sync_type' => 'analytics',
            'start_date' => $start,
            'end_date' => $end,
            'rows_synced' => 0,
            'status' => SearchConsoleSyncLog::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $rows = [];
            $exactSiteTotals = null;

            if ($isSandbox) {
                // Generate realistic deterministic analytics data for sandbox/dev
                $rows = $this->generateSandboxAnalyticsRows($website, $propertyId, $start, $end);
            } else {
                $rows = $this->querySearchAnalyticsFromGoogle($accessToken, $propertyId, $start, $end);

                // Fetch exact un-truncated 28-day site totals (dimensions: []) to store in metadata & sync log
                $snap28Start = Carbon::parse($end)->subDays(27)->toDateString();
                $exactSiteTotals = $this->queryExactTotalsFromGoogle($accessToken, $propertyId, $snap28Start, $end);
            }

            // Persist rows into search_performance_records
            // Remove previous records for this date range to avoid duplicate accumulation
            SearchPerformanceRecord::where('website_id', $website->id)
                ->whereBetween('date', [$start, $end])
                ->delete();

            $insertPayload = [];
            $totalClicks = 0;
            $totalImpressions = 0;
            $weightedPosSum = 0.0;

            foreach ($rows as $row) {
                $totalClicks += $row['clicks'];
                $totalImpressions += $row['impressions'];
                $weightedPosSum += $row['position'] * $row['impressions'];

                $insertPayload[] = [
                    'organization_id' => $website->organization_id,
                    'website_id' => $website->id,
                    'property_id' => $propertyId,
                    'date' => $row['date'],
                    'page' => $row['page'],
                    'query' => $row['query'],
                    'country' => $row['country'],
                    'device' => $row['device'],
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => $row['ctr'],
                    'position' => $row['position'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Chunk insert for performance
            foreach (array_chunk($insertPayload, 500) as $chunk) {
                SearchPerformanceRecord::insert($chunk);
            }

            $rowCount = count($insertPayload);
            $avgCtr = $totalImpressions > 0 ? round($totalClicks / $totalImpressions, 4) : 0.0;
            $avgPos = $totalImpressions > 0 ? round($weightedPosSum / $totalImpressions, 2) : 0.0;

            $summary = [
                'total_clicks' => $exactSiteTotals['clicks'] ?? $totalClicks,
                'total_impressions' => $exactSiteTotals['impressions'] ?? $totalImpressions,
                'avg_ctr' => $exactSiteTotals ? round($exactSiteTotals['ctr'] / 100, 4) : $avgCtr,
                'avg_position' => $exactSiteTotals['position'] ?? $avgPos,
                'unique_queries' => count(array_unique(array_filter(array_column($rows, 'query')))),
                'unique_pages' => count(array_unique(array_filter(array_column($rows, 'page')))),
            ];

            $syncLog->update([
                'rows_synced' => $rowCount,
                'status' => SearchConsoleSyncLog::STATUS_SUCCESS,
                'metrics_summary' => $summary,
                'completed_at' => now(),
            ]);

            $metadata = $integration->metadata ?? [];
            if ($exactSiteTotals) {
                $metadata['exact_snapshots']['28d'] = [
                    'property_id' => $propertyId,
                    'start_date' => Carbon::parse($end)->subDays(27)->toDateString(),
                    'end_date' => $end,
                    'clicks' => $exactSiteTotals['clicks'],
                    'impressions' => $exactSiteTotals['impressions'],
                    'ctr' => $exactSiteTotals['ctr'],
                    'position' => $exactSiteTotals['position'],
                    'captured_at' => now()->toIso8601String(),
                ];
            }

            $integration->update([
                'last_synced_at' => now(),
                'last_error' => null,
                'metadata' => $metadata,
            ]);

            return $syncLog;

        } catch (Throwable $e) {
            Log::error('Search Console Sync failed', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);

            $syncLog->update([
                'status' => SearchConsoleSyncLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $integration->update([
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Inspect a specific URL in Google Search Console.
     */
    public function inspectUrl(Website $website, string $url): array
    {
        $integration = $website->gscIntegration;
        if (!$integration || $integration->status !== WebsiteIntegration::STATUS_CONNECTED) {
            throw new Exception('Google Search Console is not connected.');
        }

        $propertyId = $integration->metadata['selected_property'] ?? "sc-domain:{$website->domain}";
        $credentials = $integration->getDecryptedCredentials();

        if (!empty($credentials['is_sandbox'])) {
            return [
                'url' => $url,
                'property_id' => $propertyId,
                'inspection_result' => [
                    'verdict' => 'PASS',
                    'coverage_state' => 'Submitted and indexed',
                    'indexing_state' => 'INDEXING_ALLOWED',
                    'robots_txt_state' => 'ALLOWED',
                    'last_crawl_time' => now()->subDays(2)->toIso8601String(),
                    'page_fetch_state' => 'SUCCESSFUL',
                    'google_canonical' => $url,
                    'user_canonical' => $url,
                ],
                'disclaimer' => 'URL Inspection provides Google index state at the time of check; this does not guarantee future indexing or search rankings.',
            ];
        }

        $accessToken = $this->getValidAccessToken($integration);

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $url,
                'siteUrl' => $propertyId,
            ]);

        if (!$response->successful()) {
            throw new Exception('Google URL Inspection failed: ' . ($response->json('error.message') ?? $response->body()));
        }

        $data = $response->json('inspectionResult') ?? [];

        return [
            'url' => $url,
            'property_id' => $propertyId,
            'inspection_result' => [
                'verdict' => $data['indexStatusResult']['verdict'] ?? 'NEUTRAL',
                'coverage_state' => $data['indexStatusResult']['coverageState'] ?? 'Unknown',
                'indexing_state' => $data['indexStatusResult']['indexingState'] ?? 'UNKNOWN',
                'robots_txt_state' => $data['indexStatusResult']['robotsTxtState'] ?? 'UNKNOWN',
                'last_crawl_time' => $data['indexStatusResult']['lastCrawlTime'] ?? null,
                'page_fetch_state' => $data['indexStatusResult']['pageFetchState'] ?? 'UNKNOWN',
                'google_canonical' => $data['indexStatusResult']['googleCanonical'] ?? null,
                'user_canonical' => $data['indexStatusResult']['userCanonical'] ?? null,
            ],
            'disclaimer' => 'URL Inspection provides Google index state at the time of check; this does not guarantee future indexing or search rankings.',
        ];
    }

    /**
     * Fetch exact un-truncated site totals (dimensions: []) for a date window.
     */
    private function queryExactTotalsFromGoogle(string $accessToken, string $propertyId, string $startDate, string $endDate): ?array
    {
        $encodedSiteUrl = rawurlencode($propertyId);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        try {
            $resp = Http::withToken($accessToken)
                ->timeout(15)
                ->post($endpoint, [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'type' => 'web',
                ]);

            if ($resp->successful()) {
                $row = ($resp->json('rows') ?? [])[0] ?? null;
                if ($row) {
                    $clicks = (int) round((float) ($row['clicks'] ?? 0));
                    $impressions = (int) round((float) ($row['impressions'] ?? 0));
                    return [
                        'clicks' => $clicks,
                        'impressions' => $impressions,
                        'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0,
                        'position' => $impressions > 0 ? round((float) ($row['position'] ?? 0), 1) : 0.0,
                    ];
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed fetching exact GSC totals during sync', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Query Search Analytics from Google Search Console REST API for DB persistence.
     * Uses ['date', 'page', 'query'] (3 dimensions instead of 5) to drastically reduce
     * Google's anonymized-query privacy pruning while preserving full page/query/date granularity
     * for Opportunity Detection, Rank Checker, and Keyword Gap analysis.
     */
    private function querySearchAnalyticsFromGoogle(string $accessToken, string $propertyId, string $startDate, string $endDate): array
    {
        $encodedSiteUrl = rawurlencode($propertyId);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        $allRows = [];
        $startRow = 0;
        $rowLimit = config('search_console.row_limit', 5000);

        while (true) {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->retry(
                    config('search_console.max_retries', 3),
                    config('search_console.retry_delay_ms', 1000),
                    function (Throwable $e): bool {
                        if ($e instanceof \Illuminate\Http\Client\RequestException) {
                            $status = $e->response?->status() ?? 0;
                            // Only retry transient 429 / 5xx errors — never retry 401/403 permission errors
                            return $status === 429 || $status >= 500;
                        }
                        return true;
                    },
                    throw: false
                )
                ->post($endpoint, [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['date', 'page', 'query'],
                    'type' => 'web',
                    'rowLimit' => $rowLimit,
                    'startRow' => $startRow,
                ]);

            if (!$response->successful()) {
                $rawMsg = (string) ($response->json('error.message') ?? $response->body());
                $lowerMsg = strtolower($rawMsg);

                if ($response->status() === 403 && (str_contains($lowerMsg, 'insufficient authentication scopes') || str_contains($lowerMsg, 'scope_insufficient'))) {
                    throw new Exception(
                        'Missing Google Search Console permission. Click "Reconnect Search Console" and check the box "View Search Console data for your verified sites" on Google\'s permission screen.'
                    );
                }

                if ($response->status() === 403 && str_contains($lowerMsg, 'sufficient permission for site')) {
                    throw new Exception(
                        "Your Google account does not have verified Search Console access to \"{$propertyId}\". Please select a verified property or verify this domain in Google Search Console."
                    );
                }

                throw new Exception('Google Search Analytics Query failed: ' . $rawMsg);
            }

            $rawRows = $response->json('rows') ?? [];
            if (empty($rawRows)) {
                break;
            }

            foreach ($rawRows as $item) {
                $keys = $item['keys'] ?? [];
                $allRows[] = [
                    'date' => $keys[0] ?? $startDate,
                    'page' => $keys[1] ?? '',
                    'query' => $keys[2] ?? '',
                    'country' => null,
                    'device' => null,
                    'clicks' => (int) ($item['clicks'] ?? 0),
                    'impressions' => (int) ($item['impressions'] ?? 0),
                    'ctr' => round((float) ($item['ctr'] ?? 0), 4),
                    'position' => round((float) ($item['position'] ?? 0), 2),
                ];
            }

            if (count($rawRows) < $rowLimit) {
                break;
            }

            $startRow += $rowLimit;
            // Cap at 25000 rows per run for memory safety
            if ($startRow >= 25000) {
                break;
            }
        }

        return $allRows;
    }

    /**
     * Fetch list of verified properties from Google API.
     */
    private function fetchPropertiesFromGoogle(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://www.googleapis.com/webmasters/v3/sites');

        if (!$response->successful()) {
            return [];
        }

        $siteEntries = $response->json('siteEntry') ?? [];
        return array_map(function ($entry) {
            return [
                'siteUrl' => $entry['siteUrl'] ?? '',
                'permissionLevel' => $entry['permissionLevel'] ?? 'siteRestrictedUser',
            ];
        }, $siteEntries);
    }

    /**
     * Auto-detect the property that matches the website domain.
     */
    private function determineBestMatchingProperty(Website $website, array $properties): string
    {
        $domain = preg_replace('/^www\./i', '', strtolower($website->domain));
        $candidates = [
            "sc-domain:{$domain}",
            "https://{$domain}/",
            "https://www.{$domain}/",
            "http://{$domain}/",
            "http://www.{$domain}/",
        ];

        foreach ($candidates as $candidate) {
            foreach ($properties as $prop) {
                if (strtolower($prop['siteUrl'] ?? '') === $candidate) {
                    return $prop['siteUrl'];
                }
            }
        }

        // Return first verified property or fallback to sc-domain
        return !empty($properties[0]['siteUrl']) ? $properties[0]['siteUrl'] : "sc-domain:{$domain}";
    }

    /**
     * Generate realistic sandbox search analytics data for testing and offline development.
     */
    private function generateSandboxAnalyticsRows(Website $website, string $propertyId, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $daysCount = max(1, $start->diffInDays($end) + 1);

        $domain = $website->domain;
        $baseUrl = "https://{$domain}";

        // Sample queries and target pages
        $queryCatalog = [
            ['query' => 'technical seo agency', 'slug' => '/services/technical-seo', 'base_imp' => 120, 'base_clicks' => 8, 'pos' => 4.2],
            ['query' => 'ecommerce seo audit', 'slug' => '/ecommerce-seo-audit', 'base_imp' => 240, 'base_clicks' => 14, 'pos' => 3.8],
            ['query' => 'best seo automation tools', 'slug' => '/blog/seo-automation-guide', 'base_imp' => 380, 'base_clicks' => 6, 'pos' => 8.4], // Low CTR candidate!
            ['query' => 'how to fix crawl errors', 'slug' => '/blog/fixing-crawl-errors', 'base_imp' => 190, 'base_clicks' => 12, 'pos' => 5.1],
            ['query' => 'shopify seo checklist', 'slug' => '/shopify-seo-checklist', 'base_imp' => 410, 'base_clicks' => 28, 'pos' => 2.9],
            ['query' => 'core web vitals optimization guide', 'slug' => '/core-web-vitals-guide', 'base_imp' => 210, 'base_clicks' => 16, 'pos' => 4.6],
            ['query' => 'internal linking strategy 2026', 'slug' => '/internal-linking-strategy', 'base_imp' => 160, 'base_clicks' => 9, 'pos' => 6.2],
            ['query' => 'ai meta description generator', 'slug' => '/tools/meta-description-generator', 'base_imp' => 520, 'base_clicks' => 11, 'pos' => 7.9], // Low CTR candidate!
            ['query' => 'headless wordpress seo', 'slug' => '/headless-wordpress-seo', 'base_imp' => 90, 'base_clicks' => 4, 'pos' => 9.8],
            ['query' => 'search console index coverage errors', 'slug' => '/blog/index-coverage-errors', 'base_imp' => 180, 'base_clicks' => 10, 'pos' => 5.5],
        ];

        $devices = ['DESKTOP', 'MOBILE'];
        $countries = ['USA', 'GBR', 'CAN', 'AUS'];

        $rows = [];

        for ($d = 0; $d < $daysCount; $d++) {
            $currentDate = (clone $start)->addDays($d)->toDateString();

            foreach ($queryCatalog as $idx => $item) {
                // Introduce slight variance per date
                $variance = sin($d * 0.5 + $idx) * 0.25;
                $dailyImp = max(5, (int) round(($item['base_imp'] / 14) * (1 + $variance)));
                $dailyClicks = max(0, (int) round(($item['base_clicks'] / 14) * (1 + $variance)));
                $ctr = $dailyImp > 0 ? round($dailyClicks / $dailyImp, 4) : 0.0;
                $pos = round(max(1.0, $item['pos'] + (cos($d * 0.3 + $idx) * 0.8)), 2);

                $device = $devices[$d % 2];
                $country = $countries[($d + $idx) % 4];

                $rows[] = [
                    'date' => $currentDate,
                    'page' => "{$baseUrl}{$item['slug']}",
                    'query' => $item['query'],
                    'country' => $country,
                    'device' => $device,
                    'clicks' => $dailyClicks,
                    'impressions' => $dailyImp,
                    'ctr' => $ctr,
                    'position' => $pos,
                ];
            }
        }

        return $rows;
    }
}
