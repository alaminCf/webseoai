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
                'scopes' => config('search_console.scopes'),
            ],
        ]);
    }

    /**
     * Handle OAuth callback from Google or sandbox callback.
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $integration = $this->gscService->handleCallback($validated['code'], $validated['state']);

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
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'GSC_AUTH_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 400);
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
        $range = $request->input('range', '28d');
        $endDate = Carbon::parse($request->input('end_date', now()->subDays(1)->toDateString()));
        
        $startDate = match ($range) {
            '7d' => (clone $endDate)->subDays(6),
            '3m' => (clone $endDate)->subDays(89),
            'custom' => Carbon::parse($request->input('start_date', now()->subDays(27)->toDateString())),
            default => (clone $endDate)->subDays(27), // '28d'
        };

        $days = max(1, $startDate->diffInDays($endDate) + 1);
        $prevEndDate = (clone $startDate)->subDays(1);
        $prevStartDate = (clone $prevEndDate)->subDays($days - 1);

        $startStr = $startDate->toDateString();
        $endStr = $endDate->toDateString();
        $prevStartStr = $prevStartDate->toDateString();
        $prevEndStr = $prevEndDate->toDateString();

        // Optional filters
        $country = $request->input('country');
        $device = $request->input('device');
        $queryFilter = $request->input('query');
        $pageFilter = $request->input('page');

        // Base query helper
        $buildQuery = function (string $from, string $to) use ($website, $country, $device, $queryFilter, $pageFilter) {
            $q = SearchPerformanceRecord::where('website_id', $website->id)
                ->whereBetween('date', [$from, $to]);

            if ($country) $q->where('country', strtoupper($country));
            if ($device) $q->where('device', strtoupper($device));
            if ($queryFilter) $q->where('query', 'LIKE', "%{$queryFilter}%");
            if ($pageFilter) $q->where('page', 'LIKE', "%{$pageFilter}%");

            return $q;
        };

        // 1. Current Period Summary
        $currStats = $buildQuery($startStr, $endStr)
            ->selectRaw('SUM(clicks) as total_clicks')
            ->selectRaw('SUM(impressions) as total_impressions')
            ->selectRaw('AVG(ctr) as avg_ctr')
            ->selectRaw('AVG(position) as avg_position')
            ->first();

        // 2. Previous Period Summary for Comparison
        $prevStats = $buildQuery($prevStartStr, $prevEndStr)
            ->selectRaw('SUM(clicks) as total_clicks')
            ->selectRaw('SUM(impressions) as total_impressions')
            ->selectRaw('AVG(ctr) as avg_ctr')
            ->selectRaw('AVG(position) as avg_position')
            ->first();

        $currClicks = (int) ($currStats->total_clicks ?? 0);
        $currImp = (int) ($currStats->total_impressions ?? 0);
        $currCtr = $currImp > 0 ? round(($currClicks / $currImp) * 100, 2) : 0.0;
        $currPos = round((float) ($currStats->avg_position ?? 0), 1);

        $prevClicks = (int) ($prevStats->total_clicks ?? 0);
        $prevImp = (int) ($prevStats->total_impressions ?? 0);
        $prevCtr = $prevImp > 0 ? round(($prevClicks / $prevImp) * 100, 2) : 0.0;
        $prevPos = round((float) ($prevStats->avg_position ?? 0), 1);

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
            ->selectRaw('AVG(position) as position')
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
            ->selectRaw('AVG(position) as position')
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query')
            ->orderByDesc('clicks')
            ->limit(50)
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
            ->selectRaw('AVG(position) as position')
            ->groupBy('page')
            ->orderByDesc('clicks')
            ->limit(50)
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
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
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
