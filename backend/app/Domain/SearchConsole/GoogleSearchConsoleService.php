<?php

namespace App\Domain\SearchConsole;

use App\Models\SearchConsoleSyncLog;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Carbon\Carbon;
use Exception;
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
        $this->clientId = (string) config('search_console.client_id', '');
        $this->clientSecret = (string) config('search_console.client_secret', '');
        $this->redirectUri = (string) config('search_console.redirect_uri', 'http://localhost:8001/api/v1/integrations/google-search-console/callback');
        $this->scopes = config('search_console.scopes', ['https://www.googleapis.com/auth/webmasters.readonly']);
        $this->sandboxFallback = (bool) config('search_console.sandbox_fallback', true);
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

        if (empty($this->clientId)) {
            // If OAuth credentials are not set, return sandbox authorization URL
            return url("/api/v1/integrations/google-search-console/sandbox-callback?state={$state}&code=sandbox_gsc_auth_code");
        }

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
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
        if (str_starts_with($code, 'sandbox_') || empty($this->clientId)) {
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

        if (!$accessToken) {
            throw new Exception('No access token returned from Google.');
        }

        $credentials = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addSeconds($expiresIn)->timestamp,
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'scope' => $tokenData['scope'] ?? '',
        ];

        // Fetch user's verified properties to auto-select best match
        $properties = $this->fetchPropertiesFromGoogle($accessToken);
        $selectedProperty = $this->determineBestMatchingProperty($website, $properties);

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
                    'connected_at' => now()->toIso8601String(),
                    'selected_property' => $selectedProperty,
                    'properties' => $properties,
                    'scope' => $tokenData['scope'] ?? implode(' ', $this->scopes),
                ],
            ]
        );
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

        $integration->update([
            'metadata' => $metadata,
        ]);

        return $integration;
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

        // Default to last 28 days
        $start = $startDate ? Carbon::parse($startDate)->toDateString() : now()->subDays(28)->toDateString();
        $end = $endDate ? Carbon::parse($endDate)->toDateString() : now()->subDays(1)->toDateString();

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
            $credentials = $integration->getDecryptedCredentials();
            $rows = [];

            if (!empty($credentials['is_sandbox'])) {
                // Generate realistic deterministic analytics data for sandbox/dev
                $rows = $this->generateSandboxAnalyticsRows($website, $propertyId, $start, $end);
            } else {
                $accessToken = $this->getValidAccessToken($integration);
                $rows = $this->querySearchAnalyticsFromGoogle($accessToken, $propertyId, $start, $end);
            }

            // Persist rows into search_performance_records
            // Remove previous records for this date range to avoid duplicate accumulation
            SearchPerformanceRecord::where('website_id', $website->id)
                ->whereBetween('date', [$start, $end])
                ->delete();

            $insertPayload = [];
            $totalClicks = 0;
            $totalImpressions = 0;
            $ctrSum = 0;
            $posSum = 0;

            foreach ($rows as $row) {
                $totalClicks += $row['clicks'];
                $totalImpressions += $row['impressions'];
                $ctrSum += $row['ctr'];
                $posSum += $row['position'];

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
            $avgCtr = $rowCount > 0 ? round($ctrSum / $rowCount, 4) : 0.0;
            $avgPos = $rowCount > 0 ? round($posSum / $rowCount, 2) : 0.0;

            $summary = [
                'total_clicks' => $totalClicks,
                'total_impressions' => $totalImpressions,
                'avg_ctr' => $avgCtr,
                'avg_position' => $avgPos,
                'unique_queries' => count(array_unique(array_column($rows, 'query'))),
                'unique_pages' => count(array_unique(array_column($rows, 'page'))),
            ];

            $syncLog->update([
                'rows_synced' => $rowCount,
                'status' => SearchConsoleSyncLog::STATUS_SUCCESS,
                'metrics_summary' => $summary,
                'completed_at' => now(),
            ]);

            $integration->update([
                'last_synced_at' => now(),
                'last_error' => null,
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
     * Query Search Analytics from Google Search Console REST API.
     */
    private function querySearchAnalyticsFromGoogle(string $accessToken, string $propertyId, string $startDate, string $endDate): array
    {
        $encodedSiteUrl = urlencode($propertyId);
        $endpoint = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

        $allRows = [];
        $startRow = 0;
        $rowLimit = config('search_console.row_limit', 5000);

        while (true) {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->retry(config('search_console.max_retries', 3), config('search_console.retry_delay_ms', 1000))
                ->post($endpoint, [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['date', 'page', 'query', 'country', 'device'],
                    'rowLimit' => $rowLimit,
                    'startRow' => $startRow,
                ]);

            if (!$response->successful()) {
                throw new Exception('Google Search Analytics Query failed: ' . ($response->json('error.message') ?? $response->body()));
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
                    'country' => strtoupper($keys[3] ?? 'USA'),
                    'device' => strtoupper($keys[4] ?? 'DESKTOP'),
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
        $domain = strtolower($website->domain);
        $scDomain = "sc-domain:{$domain}";
        $urlPrefixHttps = "https://{$domain}/";
        $urlPrefixHttp = "http://{$domain}/";

        foreach ($properties as $prop) {
            $url = strtolower($prop['siteUrl']);
            if ($url === $scDomain) return $prop['siteUrl'];
            if ($url === $urlPrefixHttps) return $prop['siteUrl'];
            if ($url === $urlPrefixHttp) return $prop['siteUrl'];
        }

        // Return first or fallback to sc-domain
        return !empty($properties[0]['siteUrl']) ? $properties[0]['siteUrl'] : $scDomain;
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
