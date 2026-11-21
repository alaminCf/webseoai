<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Performance\OpportunityDetector;
use App\Http\Controllers\Controller;
use App\Models\SearchOpportunity;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchOpportunityController extends Controller
{
    private OpportunityDetector $detector;

    public function __construct()
    {
        $this->detector = new OpportunityDetector();
    }

    /**
     * List search opportunities for a website.
     */
    public function index(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $query = SearchOpportunity::where('website_id', $website->id);

        if ($request->has('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        $opportunities = $query->orderByDesc('impact_score')
            ->orderByDesc('detected_at')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $opportunities->items(),
            'meta' => [
                'current_page' => $opportunities->currentPage(),
                'last_page' => $opportunities->lastPage(),
                'total' => $opportunities->total(),
            ],
        ]);
    }

    /**
     * Trigger fresh opportunity detection across search console & audit findings.
     */
    public function detect(Request $request, Website $website): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        $daysWindow = (int) $request->input('days_window', 28);
        $detected = $this->detector->detectOpportunities($website, $daysWindow);

        return response()->json([
            'success' => true,
            'message' => "Detected {$detected->count()} actionable search performance opportunities.",
            'data' => $detected,
        ]);
    }

    /**
     * Update opportunity status (e.g. acknowledge, dismiss, resolve).
     */
    public function updateStatus(Request $request, Website $website, SearchOpportunity $opportunity): JsonResponse
    {
        $this->authorizeWebsite($request, $website);

        if ($opportunity->website_id !== $website->id) {
            abort(404, 'Opportunity not found for this website.');
        }

        $validated = $request->validate([
            'status' => 'required|in:detected,acknowledged,dismissed,resolved',
        ]);

        $opportunity->update([
            'status' => $validated['status'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Opportunity status updated.',
            'data' => $opportunity,
        ]);
    }

    private function authorizeWebsite(Request $request, Website $website): void
    {
        $orgId = $request->header('X-Organization-Id');
        if ($orgId && (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'Unauthorized access to website.');
        }
    }
}
