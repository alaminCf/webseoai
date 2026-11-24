<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SearchConsole\GoogleSearchConsoleService;
use App\Http\Controllers\Controller;
use App\Models\SearchConsoleSyncLog;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class SearchConsoleController extends Controller
{
    private GoogleSearchConsoleService $gscService;

    public function __construct(GoogleSearchConsoleService $gscService)
    {
        $this->gscService = $gscService;
    }

    /**
     * Get OAuth authorization URL or trigger sandbox connection.
     */
    public function connect(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $authUrl = $this->gscService->getAuthorizationUrl($website, $request->user()->id);

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_url' => $authUrl,
                'oauth_mode' => $this->gscService->isLiveConfigured() ? 'live' : 'sandbox',
                'redirect_uri' => $this->gscService->getRedirectUri(),
                'scopes' => config('search_console.scopes'),
            ],
        ]);
    }

    /**
     * Handle OAuth callback from Google (browser redirect) or sandbox callback (JSON).
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        $oauthError = (string) $request->query('error', '');
        $isApiCall = $request->expectsJson() || str_starts_with($code, 'sandbox_');

        $frontendBase = rtrim((string) (config('search_console.frontend_url') ?: url('/')), '/');
        $decodedWebsiteId = null;
        $oauthIntent = 'gsc';
        if ($state !== '') {
            $decoded = json_decode(base64_decode($state), true);
            if (is_array($decoded)) {
                if (isset($decoded['website_id'])) {
                    $decodedWebsiteId = (int) $decoded['website_id'];
                }
                if (($decoded['intent'] ?? '') === 'ga4') {
                    $oauthIntent = 'ga4';
                }
            }
        }

        // User cancelled or Google returned an OAuth error
        if ($oauthError !== '') {
            $reason = $request->query('error_description') ?: "Google OAuth error: {$oauthError}";
            if ($isApiCall) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'GSC_AUTH_DENIED',
                        'message' => $reason,
                    ],
                ], 400);
            }

            $params = array_filter([
                'tab' => 'performance',
                'gsc' => 'error',
                'reason' => $reason,
                'website_id' => $decodedWebsiteId,
            ], fn ($v) => $v !== null && $v !== '');

            return redirect()->away($frontendBase . '/?' . http_build_query($params));
        }

        if ($code === '' || $state === '') {
            if ($isApiCall) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => 'Missing required OAuth code or state parameter.',
                    ],
                ], 422);
            }

            return redirect()->away($frontendBase . '/?' . http_build_query([
                'tab' => 'performance',
                'gsc' => 'error',
                'reason' => 'Missing required OAuth code or state parameter.',
            ]));
        }

        try {
            $integration = $this->gscService->handleCallback($code, $state);

            if ($isApiCall) {
                return response()->json([
                    'success' => true,
                    'message' => 'Google Search Console connected successfully.',
                    'data' => [
                        'status' => $integration->status,
                        'selected_property' => $integration->metadata['selected_property'] ?? null,
                        'properties' => $integration->metadata['properties'] ?? [],
                        'is_sandbox' => !empty($integration->metadata['is_sandbox']),
                    ],
                ]);
            }

            return redirect()->away($frontendBase . '/?' . http_build_query([
                'tab' => $oauthIntent === 'ga4' ? 'live_traffic' : 'performance',
                $oauthIntent === 'ga4' ? 'ga4' : 'gsc' => 'connected',
                'website_id' => $integration->website_id,
            ]));
        } catch (Throwable $e) {
            if ($isApiCall) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'GSC_AUTH_FAILED',
                        'message' => $e->getMessage(),
                    ],
                ], 400);
            }

            $params = array_filter([
                'tab' => 'performance',
                'gsc' => 'error',
                'reason' => $e->getMessage(),
                'website_id' => $decodedWebsiteId,
            ], fn ($v) => $v !== null && $v !== '');

            return redirect()->away($frontendBase . '/?' . http_build_query($params));
        }
    }

    /**
     * Disconnect Google Search Console integration.
     */
    public function disconnect(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $this->gscService->disconnect($website);

        return response()->json([
            'success' => true,
            'message' => 'Google Search Console disconnected.',
        ]);
    }

    /**
     * Get integration status and sync health.
     */
    public function status(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $integration = $website->gscIntegration;
        $latestSync = SearchConsoleSyncLog::where('website_id', $website->id)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'connected' => $integration && $integration->status === WebsiteIntegration::STATUS_CONNECTED,
                'status' => $integration ? $integration->status : WebsiteIntegration::STATUS_DISCONNECTED,
                'selected_property' => $integration->metadata['selected_property'] ?? null,
                'properties' => $integration->metadata['properties'] ?? [],
                'last_synced_at' => $integration ? $integration->last_synced_at : null,
                'last_error' => $integration ? $integration->last_error : null,
                'is_sandbox' => !empty($integration->metadata['is_sandbox']),
                'oauth_mode' => $this->gscService->isLiveConfigured() ? 'live' : 'sandbox',
                'redirect_uri' => $this->gscService->getRedirectUri(),
                'latest_sync_log' => $latestSync,
            ],
        ]);
    }

    /**
     * Select active property.
     */
    public function selectProperty(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'property_id' => 'required|string',
        ]);

        $integration = $website->gscIntegration;
        if (!$integration) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_CONNECTED', 'message' => 'Search Console is not connected.'],
            ], 400);
        }

        $this->gscService->selectProperty($integration, $validated['property_id']);

        return response()->json([
            'success' => true,
            'message' => 'Property selected successfully.',
            'data' => [
                'selected_property' => $validated['property_id'],
            ],
        ]);
    }

    /**
     * Trigger search analytics sync.
     */
    public function sync(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        try {
            $syncLog = $this->gscService->syncSearchAnalytics($website, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'message' => 'Search Console data synchronized successfully.',
                'data' => $syncLog,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SYNC_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Get search performance overview, tables, and trend analytics.
     */
    public function performance(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        // Date range parsing (default: last 28 days)
        $range = (string) $request->input('range', '28d');
        $customStart = $request->input('start_date');
        $customEnd = $request->input('end_date');
        $forceRefresh = $request->boolean('refresh', false);

        // Optional filters
        $country = $request->input('country');
        $device = $request->input('device');
        $queryFilter = $request->input('query');
        $pageFilter = $request->input('page');

        // 1. Live Google Search Console exact query (100% parity with official GSC UI)
        try {
            $liveData = $this->gscService->fetchLivePerformanceReport(
                $website,
                $range,
                $customStart ? (string) $customStart : null,
                $customEnd ? (string) $customEnd : null,
                [
                    'country' => $country,
                    'device' => $device,
                    'query' => $queryFilter,
                    'page' => $pageFilter,
                ],
                $forceRefresh
            );

            if ($liveData !== null) {
                return response()->json([
                    'success' => true,
                    'data' => $liveData,
                ]);
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Live GSC performance query failed, falling back to synced DB records', [
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Database / Sandbox fallback path (with impression-weighted average position)
        $latestRecordDate = SearchPerformanceRecord::where('website_id', $website->id)->max('date');
        $defaultEnd = $latestRecordDate
            ? Carbon::parse($latestRecordDate)
            : now()->subDays(1);

        $endDate = $customEnd ? Carbon::parse($customEnd) : $defaultEnd;

        $startDate = match ($range) {
            '7d' => (clone $endDate)->subDays(6),
            '3m', '90d' => (clone $endDate)->subDays(89),
            'custom' => Carbon::parse($customStart ?: (clone $endDate)->subDays(27)->toDateString()),
            default => (clone $endDate)->subDays(27), // '28d'
        };

        $days = max(1, $startDate->diffInDays($endDate) + 1);
        $prevEndDate = (clone $startDate)->subDays(1);
        $prevStartDate = (clone $prevEndDate)->subDays($days - 1);

        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();
        $prevStartStr = $prevStartDate->toDateString();
        $prevEndStr = $prevEndDate->toDateString();

        // Base query helper
        $buildQuery = function (string $from, string $to) use ($website, $country, $device, $queryFilter, $pageFilter) {
            $q = SearchPerformanceRecord::where('website_id', $website->id)
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to);

            if ($country) $q->where('country', strtoupper($country));
            if ($device) $q->where('device', strtoupper($device));
            if ($queryFilter) $q->where('query', 'LIKE', "%{$queryFilter}%");
            if ($pageFilter) $q->where('page', 'LIKE', "%{$pageFilter}%");

            return $q;
        };

        $weightedPosSql = 'CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END';

        // 1. Current Period Summary (impression-weighted position)
        $currStats = $buildQuery($startStr, $endStr)
            ->selectRaw('SUM(clicks) as total_clicks')
            ->selectRaw('SUM(impressions) as total_impressions')
            ->selectRaw("{$weightedPosSql} as avg_position")
            ->first();

        // 2. Previous Period Summary for Comparison
        $prevStats = $buildQuery($prevStartStr, $prevEndStr)
            ->selectRaw('SUM(clicks) as total_clicks')
            ->selectRaw('SUM(impressions) as total_impressions')
            ->selectRaw("{$weightedPosSql} as avg_position")
            ->first();

        $currClicks = (int) ($currStats->total_clicks ?? 0);
        $currImp = (int) ($currStats->total_impressions ?? 0);
        $currCtr = $currImp > 0 ? round(($currClicks / $currImp) * 100, 2) : 0.0;
        $currPos = $currImp > 0 ? round((float) ($currStats->avg_position ?? 0), 1) : 0.0;

        // If unfiltered fallback has an exact GSC snapshot cached on integration metadata, prefer its exact totals
        $hasFilters = (bool) ($country || $device || $queryFilter || $pageFilter);
        $integration = $website->gscIntegration;
        $snapKey = $range === '3m' ? '90d' : $range;
        $exactSnap = (!$hasFilters && $integration) ? ($integration->metadata['exact_snapshots'][$snapKey] ?? null) : null;
        if (is_array($exactSnap) && isset($exactSnap['clicks'], $exactSnap['impressions'])) {
            $currClicks = (int) $exactSnap['clicks'];
            $currImp = (int) $exactSnap['impressions'];
            $currCtr = (float) ($exactSnap['ctr'] ?? ($currImp > 0 ? round(($currClicks / $currImp) * 100, 2) : 0.0));
            $currPos = (float) ($exactSnap['position'] ?? $currPos);
        }

        $prevClicks = (int) ($prevStats->total_clicks ?? 0);
        $prevImp = (int) ($prevStats->total_impressions ?? 0);
        $prevCtr = $prevImp > 0 ? round(($prevClicks / $prevImp) * 100, 2) : 0.0;
        $prevPos = $prevImp > 0 ? round((float) ($prevStats->avg_position ?? 0), 1) : 0.0;

        // Delta calculations
        $diffClicks = $currClicks - $prevClicks;
        $diffImp = $currImp - $prevImp;
        $diffCtr = round($currCtr - $prevCtr, 2);
        $diffPos = round($currPos - $prevPos, 1); // negative is improvement in rank

        // 3. Daily Timeseries Chart Data
        $timeseries = $buildQuery($startStr, $endStr)
            ->select('date')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw("{$weightedPosSql} as position")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date->toDateString(),
                    'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                    'ctr' => $row->impressions > 0 ? round(($row->clicks / $row->impressions) * 100, 2) : 0.0,
                    'position' => round((float) $row->position, 1),
                ];
            });

        // 4. Top Queries Table
        $queries = $buildQuery($startStr, $endStr)
            ->select('query')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw("{$weightedPosSql} as position")
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query')
            ->orderByDesc('clicks')
            ->orderByDesc('impressions')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                return [
                    'query' => $row->query,
                    'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                    'ctr' => $row->impressions > 0 ? round(($row->clicks / $row->impressions) * 100, 2) : 0.0,
                    'position' => round((float) $row->position, 1),
                ];
            });

        // 5. Top Pages Table
        $pages = $buildQuery($startStr, $endStr)
            ->select('page')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(impressions) as impressions')
            ->selectRaw("{$weightedPosSql} as position")
            ->groupBy('page')
            ->orderByDesc('clicks')
            ->orderByDesc('impressions')
            ->limit(100)
            ->get()
            ->map(function ($row) {
                return [
                    'page' => $row->page,
                    'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions,
                    'ctr' => $row->impressions > 0 ? round(($row->clicks / $row->impressions) * 100, 2) : 0.0,
                    'position' => round((float) $row->position, 1),
                ];
            });

        // 6. Device Breakdown
        $devices = $buildQuery($startStr, $endStr)
            ->select('device')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(impressions) as impressions')
            ->whereNotNull('device')
            ->groupBy('device')
            ->get();

        // 7. Country Breakdown
        $countries = $buildQuery($startStr, $endStr)
            ->select('country')
            ->selectRaw('SUM(clicks) as clicks')
            ->selectRaw('SUM(impressions) as impressions')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('clicks')
            ->orderByDesc('impressions')
            ->limit(25)
            ->get();

        $isSandbox = !empty($integration?->metadata['is_sandbox']);

        return response()->json([
            'success' => true,
            'data' => [
                'data_source' => $isSandbox ? 'sandbox' : 'synced_database',
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
                    'comparison' => [
                        'previous_clicks' => $prevClicks,
                        'previous_impressions' => $prevImp,
                        'previous_ctr' => $prevCtr,
                        'previous_position' => $prevPos,
                        'diff_clicks' => $diffClicks,
                        'diff_impressions' => $diffImp,
                        'diff_ctr' => $diffCtr,
                        'diff_position' => $diffPos,
                    ],
                ],
                'timeseries' => $timeseries,
                'queries' => $queries,
                'pages' => $pages,
                'devices' => $devices,
                'countries' => $countries,
            ],
        ]);
    }

    /**
     * URL Inspection endpoint.
     */
    public function inspectUrl(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        try {
            $inspection = $this->gscService->inspectUrl($website, $validated['url']);

            return response()->json([
                'success' => true,
                'data' => $inspection,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INSPECTION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        $orgId = $request->header('X-Organization-Id');
        if ($orgId && (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'Unauthorized access to website.');
        }
    }
}
