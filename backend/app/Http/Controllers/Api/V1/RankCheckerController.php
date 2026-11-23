<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\RankChecker\RankCheckerService;
use App\Http\Controllers\Controller;
use App\Models\CompetitorSerpSnapshot;
use App\Models\Organization;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RankCheckerController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private RankCheckerService $rankCheckerService)
    {
    }

    /**
     * Get GSC quick-check keywords, recent rank checks history, and SERP provider status.
     */
    public function context(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        return $this->success($this->rankCheckerService->getContext($website));
    }

    /**
     * Execute a live geo-located Google search rank check for any keyword + country + city + device.
     */
    public function check(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'keyword' => ['required', 'string', 'min:1', 'max:200'],
            'country' => ['nullable', 'string', 'size:2'],
            'language' => ['nullable', 'string', 'max:10'],
            'device' => ['nullable', 'string', Rule::in(['desktop', 'mobile'])],
            'location' => ['nullable', 'string', 'max:150'],
            'depth' => ['nullable', 'integer', Rule::in([20, 30, 50, 100])],
            'monitor' => ['nullable', 'boolean'],
            'force_refresh' => ['nullable', 'boolean'],
            'stop_when_found' => ['nullable', 'boolean'],
        ]);

        $res = $this->rankCheckerService->checkRank(
            $website,
            $validated['keyword'],
            $validated['country'] ?? ($website->target_country ?: 'US'),
            $validated['language'] ?? ($website->preferred_language ?: 'en'),
            $validated['device'] ?? 'desktop',
            $validated['location'] ?? null,
            (int) ($validated['depth'] ?? 100),
            (bool) ($validated['monitor'] ?? false),
            array_key_exists('force_refresh', $validated) ? (bool) $validated['force_refresh'] : true,
            array_key_exists('stop_when_found', $validated) ? (bool) $validated['stop_when_found'] : true
        );

        if (!($res['available'] ?? false)) {
            return $this->error(
                $res['error'] ?? 'Could not run live rank check.',
                'RANK_CHECK_UNAVAILABLE',
                null,
                422
            );
        }

        return $this->success($res, null, 201);
    }

    /**
     * Load a previously stored rank check snapshot (zero API credits spent).
     */
    public function showSnapshot(Request $request, Website $website, CompetitorSerpSnapshot $snapshot): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ((int) $snapshot->website_id !== (int) $website->id) {
            return $this->error('Rank check snapshot not found for this website.', 'NOT_FOUND', null, 404);
        }

        return $this->success($this->rankCheckerService->formatStoredSnapshot($website, $snapshot));
    }

    /**
     * Live-verify and store an encrypted workspace Serper.dev API key.
     */
    public function saveProviderKey(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $validated = $request->validate([
            'serper_api_key' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        $res = $this->rankCheckerService->saveSerperKey($website, $validated['serper_api_key']);
        if (!($res['saved'] ?? false)) {
            return $this->error(
                $res['error'] ?? 'Could not verify Serper.dev API key.',
                'SERPER_KEY_INVALID',
                null,
                422
            );
        }

        return $this->success($res);
    }

    /**
     * Disconnect the workspace-stored Serper.dev API key.
     */
    public function deleteProviderKey(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $this->rankCheckerService->removeSerperKey($website);

        return $this->success(['removed' => true]);
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
}
