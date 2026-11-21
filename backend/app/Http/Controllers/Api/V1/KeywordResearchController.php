<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Keywords\KeywordManager;
use App\Http\Controllers\Controller;
use App\Models\KeywordResearchRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KeywordResearchController extends Controller
{
    private KeywordManager $keywordManager;

    public function __construct(KeywordManager $keywordManager)
    {
        $this->keywordManager = $keywordManager;
    }

    /**
     * Search seed keywords and return metrics with provenance.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:200',
            'website_id' => 'nullable|integer',
            'country' => 'nullable|string|size:2',
            'language' => 'nullable|string|size:2',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $orgId = (int) $request->header('X-Organization-Id', $request->user()->current_organization_id ?? 1);

        $results = $this->keywordManager->searchAndStore(
            $validated['query'],
            $orgId,
            $validated['website_id'] ?? null,
            [
                'country' => $validated['country'] ?? 'US',
                'language' => $validated['language'] ?? 'en',
                'limit' => $validated['limit'] ?? 20,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * Get semantic keyword clusters for a query.
     */
    public function clusters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => 'required|string|min:2',
            'country' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $clusters = $this->keywordManager->getClusters($validated['keyword'], [
            'country' => $validated['country'] ?? 'US',
            'language' => $validated['language'] ?? 'en',
        ]);

        return response()->json([
            'success' => true,
            'data' => $clusters,
        ]);
    }

    /**
     * List saved keyword research history for the organization/website.
     */
    public function history(Request $request): JsonResponse
    {
        $orgId = (int) $request->header('X-Organization-Id', $request->user()->current_organization_id ?? 1);

        $query = KeywordResearchRecord::where('organization_id', $orgId);

        if ($request->has('website_id')) {
            $query->where('website_id', $request->input('website_id'));
        }

        if ($request->has('search')) {
            $term = $request->input('search');
            $query->where('keyword', 'LIKE', "%{$term}%");
        }

        $records = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $records->items(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'total' => $records->total(),
            ],
        ]);
    }
}
