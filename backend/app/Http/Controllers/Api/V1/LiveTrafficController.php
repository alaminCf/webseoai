<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Traffic\GoogleAnalyticsService;
use App\Domain\Traffic\LiveTrafficService;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use App\Models\WebsiteTrafficEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LiveTrafficController extends Controller
{
    use ApiResponseTrait;

    private const TRANSPARENT_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        private LiveTrafficService $trafficService,
        private GoogleAnalyticsService $ga4Service
    ) {
    }

    /**
     * Public: Serve the tiny CORS-proof JavaScript live tracker snippet for a website token.
     */
    public function script(Request $request, string $token): Response
    {
        $website = Website::where('tracking_token', $token)->where('is_archived', false)->first();
        if (!$website) {
            return response('/* Web SEO AI tracker: invalid token */', 404, [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Access-Control-Allow-Origin' => '*',
            ]);
        }

        $origin = $this->resolvePublicOrigin($request);
        $collectUrl = "{$origin}/api/v1/t/collect";
        $pixelUrl = "{$origin}/api/v1/t/p.gif";
        $js = $this->trafficService->renderTrackerScript($website, $collectUrl, $pixelUrl);

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Public: CORS preflight for cross-origin tracking beacons.
     */
    public function preflight(): Response
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * Public: Ingest live visitor pageview / heartbeat beacon (supports text/plain & JSON, filters bots).
     */
    public function collect(Request $request): Response
    {
        if ($this->trafficService->isBotUserAgent($request->userAgent())) {
            return response('', 204, ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store']);
        }

        $payload = [];
        $rawContent = trim($request->getContent());
        if ($rawContent !== '') {
            $decoded = json_decode($rawContent, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
        if (empty($payload)) {
            $payload = $request->all();
        }

        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return response('', 400, ['Access-Control-Allow-Origin' => '*']);
        }

        $website = Website::where('tracking_token', $token)->where('is_archived', false)->first();
        if (!$website) {
            return response('', 404, ['Access-Control-Allow-Origin' => '*']);
        }

        $this->trafficService->recordEvent($website, $request, $payload, false);

        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Public: 1x1 transparent GIF pixel beacon fallback (100% immune to CORS/preflight restrictions).
     */
    public function pixel(Request $request): Response
    {
        $gif = base64_decode(self::TRANSPARENT_GIF);
        $headers = [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Access-Control-Allow-Origin' => '*',
        ];

        if ($this->trafficService->isBotUserAgent($request->userAgent())) {
            return response($gif, 200, $headers);
        }

        $token = trim((string) $request->query('t', ''));
        if ($token !== '') {
            $website = Website::where('tracking_token', $token)->where('is_archived', false)->first();
            if ($website) {
                $this->trafficService->recordEvent($website, $request, [
                    'event_type' => (string) $request->query('e', 'pageview'),
                    'client_id' => (string) $request->query('v', ''),
                    'session_id' => (string) $request->query('s', ''),
                    'path' => (string) $request->query('p', '/'),
                    'title' => (string) $request->query('ti', ''),
                    'referrer' => (string) $request->query('r', ''),
                ], false);
            }
        }

        return response($gif, 200, $headers);
    }

    /**
     * Authenticated: Get unified real-time live traffic stats (Google Analytics 4 OR First-Party Tracker).
     */
    public function realtime(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $range = in_array($request->query('range'), ['30m', '24h', '7d', '30d'], true)
            ? (string) $request->query('range')
            : '24h';
        $country = $request->query('country') ? (string) $request->query('country') : null;
        $source = in_array($request->query('source'), ['auto', 'ga4', 'tracker'], true)
            ? (string) $request->query('source')
            : 'auto';
        $includeTest = $request->boolean('include_test', false);

        // Base first-party tracker payload (always provides tracker snippet & real/test counts)
        $trackerData = $this->trafficService->getRealtimeStats(
            $website,
            $range,
            $country,
            $this->resolvePublicOrigin($request),
            $includeTest
        );

        // Build GA4 integration status metadata
        $ga4Integration = $website->ga4Integration;
        $ga4Connected = $ga4Integration && $ga4Integration->status === WebsiteIntegration::STATUS_CONNECTED;
        $ga4Meta = is_array($ga4Integration?->metadata) ? $ga4Integration->metadata : [];
        $ga4Status = [
            'connected' => $ga4Connected,
            'selected_property_id' => $ga4Meta['selected_property_id'] ?? null,
            'selected_property_name' => $ga4Meta['selected_property_name'] ?? null,
            'properties' => $ga4Meta['properties'] ?? [],
            'last_synced_at' => $ga4Integration?->last_synced_at?->toIso8601String(),
            'last_error' => $ga4Integration?->last_error,
            'oauth_mode' => $this->ga4Service->isLiveConfigured() ? 'live' : 'sandbox',
        ];

        $ga4Error = null;
        $shouldUseGa4 = $ga4Connected && !empty($ga4Status['selected_property_id']) && (
            $source === 'ga4'
            || ($source === 'auto' && !($trackerData['tracker']['has_real_events'] ?? false))
            || $source === 'auto'
        );

        // If user explicitly wants tracker, skip GA4 fetch
        if ($source === 'tracker') {
            $shouldUseGa4 = false;
        }

        if ($shouldUseGa4 && $ga4Integration) {
            $ga4Res = $this->ga4Service->getRealtimeDashboard($website, $ga4Integration, $range, $country);
            if ($ga4Res['available'] ?? false) {
                $ga4Res['tracker'] = $trackerData['tracker'];
                $ga4Res['ga4'] = $ga4Status;
                $ga4Res['ga4_error'] = null;
                $ga4Res['include_test'] = $includeTest;
                $ga4Res['generated_at'] = now()->toIso8601String();
                return $this->success($ga4Res);
            }
            $ga4Error = $ga4Res['error'] ?? 'GA4 query failed.';
            $ga4Status['last_error'] = $ga4Error;
        }

        $trackerData['ga4'] = $ga4Status;
        $trackerData['ga4_error'] = $ga4Error;

        return $this->success($trackerData);
    }

    /**
     * Authenticated: Verify whether the tracker script is actually live on the website's HTML
     * AND auto-detect any existing GA4 (G-...) / GTM tag.
     */
    public function verifyInstall(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $report = $this->trafficService->verifyLiveInstallation($website);

        return $this->success($report);
    }

    /**
     * Authenticated: Record a marked test visit (is_test = true) so real traffic counts are NEVER inflated.
     */
    public function testPing(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $event = $this->trafficService->recordEvent($website, $request, [
            'event_type' => 'pageview',
            'path' => (string) $request->input('path', '/'),
            'title' => (string) $request->input('title', "{$website->name} — Live Test Visit"),
            'referrer' => (string) $request->input('referrer', 'https://www.google.com/'),
        ], true);

        return $this->success([
            'event' => $event,
            'message' => 'Test visit recorded separately from real website traffic.',
        ], null, 201);
    }

    /**
     * Authenticated: Delete synthetic test visits so analytics stay 100% pure real traffic.
     */
    public function clearTestEvents(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $deleted = WebsiteTrafficEvent::where('website_id', $website->id)
            ->where('is_test', true)
            ->delete();

        return $this->success([
            'deleted_count' => $deleted,
            'message' => "Cleared {$deleted} test visit(s).",
        ]);
    }

    /**
     * Authenticated: Get Google Analytics 4 (GA4) OAuth authorization URL (or reuse existing Google token if scope granted).
     */
    public function connectGa4(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        // If the website's existing GSC token already includes analytics.readonly scope, reuse it immediately!
        $gsc = $website->gscIntegration;
        if ($gsc && $gsc->status === WebsiteIntegration::STATUS_CONNECTED) {
            $creds = $gsc->getDecryptedCredentials() ?? [];
            $scope = (string) ($creds['scope'] ?? '');
            if (str_contains($scope, 'analytics.readonly') && empty($creds['is_sandbox'])) {
                $ga4 = $this->ga4Service->storeFromTokens($website, $creds);
                return $this->success([
                    'reused_google_oauth' => true,
                    'authorization_url' => null,
                    'ga4' => [
                        'connected' => true,
                        'selected_property_id' => $ga4->metadata['selected_property_id'] ?? null,
                        'selected_property_name' => $ga4->metadata['selected_property_name'] ?? null,
                        'properties' => $ga4->metadata['properties'] ?? [],
                    ],
                ]);
            }
        }

        $authUrl = $this->ga4Service->getAuthorizationUrl($website, $request->user()->id);

        return $this->success([
            'reused_google_oauth' => false,
            'authorization_url' => $authUrl,
            'oauth_mode' => $this->ga4Service->isLiveConfigured() ? 'live' : 'sandbox',
        ]);
    }

    /**
     * Authenticated: Select or manually enter a GA4 Numeric Property ID.
     */
    public function selectGa4Property(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'property_id' => ['required', 'string', 'max:64'],
        ]);

        $ga4 = $website->ga4Integration;
        if (!$ga4 || $ga4->status !== WebsiteIntegration::STATUS_CONNECTED) {
            return $this->error('Google Analytics 4 is not connected yet.', 'GA4_NOT_CONNECTED', null, 400);
        }

        $updated = $this->ga4Service->selectProperty($ga4, $validated['property_id']);

        return $this->success([
            'selected_property_id' => $updated->metadata['selected_property_id'] ?? null,
            'selected_property_name' => $updated->metadata['selected_property_name'] ?? null,
        ]);
    }

    /**
     * Authenticated: Disconnect Google Analytics 4.
     */
    public function disconnectGa4(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        WebsiteIntegration::where('website_id', $website->id)
            ->where('provider', WebsiteIntegration::PROVIDER_GA4)
            ->delete();

        return $this->success(['message' => 'Google Analytics 4 disconnected.']);
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');
        $orgId = $org?->id ?? $request->header('X-Organization-Id');

        if (!$orgId || (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'You do not have access to this website.');
        }
    }

    private function resolvePublicOrigin(Request $request): string
    {
        $configured = rtrim((string) config('search_console.frontend_url', ''), '/');
        if ($configured !== '' && !str_contains($configured, 'localhost')) {
            return $configured;
        }
        $scheme = $request->header('x-forwarded-proto') ?: $request->getScheme();
        $host = $request->header('x-forwarded-host') ?: $request->getHttpHost();
        return rtrim("{$scheme}://{$host}", '/');
    }
}
