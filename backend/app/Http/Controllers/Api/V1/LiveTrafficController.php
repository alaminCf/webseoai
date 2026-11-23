<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Traffic\LiveTrafficService;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LiveTrafficController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private LiveTrafficService $trafficService)
    {
    }

    /**
     * Public: Serve the tiny JavaScript live tracker snippet for a website token.
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
        $js = $this->trafficService->renderTrackerScript($website, $collectUrl);

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
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Max-Age' => '86400',
        ]);
    }

    /**
     * Public: Ingest live visitor pageview / heartbeat beacon from tracked websites.
     */
    public function collect(Request $request): Response
    {
        $payload = $request->all();
        if (empty($payload) && $request->getContent() !== '') {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return response('', 400, ['Access-Control-Allow-Origin' => '*']);
        }

        $website = Website::where('tracking_token', $token)->where('is_archived', false)->first();
        if (!$website) {
            return response('', 404, ['Access-Control-Allow-Origin' => '*']);
        }

        $this->trafficService->recordEvent($website, $request, $payload);

        return response('', 204, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Authenticated: Get real-time live traffic stats, country/region/city breakdown, and live visitor stream.
     */
    public function realtime(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $range = in_array($request->query('range'), ['30m', '24h', '7d', '30d'], true)
            ? (string) $request->query('range')
            : '24h';
        $country = $request->query('country') ? (string) $request->query('country') : null;

        $data = $this->trafficService->getRealtimeStats(
            $website,
            $range,
            $country,
            $this->resolvePublicOrigin($request)
        );

        return $this->success($data);
    }

    /**
     * Authenticated: Record a live test visit from the current user's browser so they can immediately
     * verify real-time tracking, country/region detection, and live stream updates.
     */
    public function testPing(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $event = $this->trafficService->recordEvent($website, $request, [
            'event_type' => 'pageview',
            'path' => (string) $request->input('path', '/'),
            'title' => (string) $request->input('title', "{$website->name} — Live Verification Visit"),
            'referrer' => (string) $request->input('referrer', 'https://www.google.com/'),
        ]);

        return $this->success([
            'event' => $event,
            'message' => 'Live test visit recorded with real geo detection.',
        ], null, 201);
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
