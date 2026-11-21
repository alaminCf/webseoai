<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\Organization;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    use ApiResponseTrait;

    /**
     * Serialize an AuditRun with complete, consistent API contract fields.
     */
    protected function formatAuditRun(AuditRun $run): array
    {
        $stats = $run->summary_stats ?? [];
        $totalPages = (int) ($stats['total_pages_crawled'] ?? 0);

        return [
            'id' => $run->id,
            'website_id' => $run->website_id,
            'organization_id' => $run->organization_id,
            'crawl_job_id' => $run->crawl_job_id,
            'overall_score' => $run->overall_score,
            'technical_score' => $run->technical_score,
            'content_score' => $run->content_score,
            'link_score' => $run->link_score,
            'links_score' => $run->link_score,
            'indexing_score' => $run->indexing_score ?? 100,
            'critical_count' => $run->critical_count,
            'warning_count' => $run->warning_count,
            'info_count' => $run->info_count,
            'total_issues' => $run->critical_count + $run->warning_count + $run->info_count,
            'total_pages_analyzed' => $totalPages,
            'summary_stats' => $stats,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * List historical audit runs for a website.
     */
    public function listAudits(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $audits = AuditRun::where('website_id', $website->id)
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 15));

        $data = $audits->getCollection()->map(fn(AuditRun $a) => $this->formatAuditRun($a));

        return $this->success($data, [
            'current_page' => $audits->currentPage(),
            'per_page' => $audits->perPage(),
            'total' => $audits->total(),
            'last_page' => $audits->lastPage(),
        ]);
    }

    /**
     * Get latest audit run for a website.
     */
    public function getLatestAudit(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $latest = AuditRun::where('website_id', $website->id)
            ->orderBy('id', 'desc')
            ->first();

        if (!$latest) {
            return $this->success(null);
        }

        return $this->success($this->formatAuditRun($latest));
    }

    /**
     * List issues for a specific audit run with search and multi-facet filtering.
     */
    public function listIssues(Request $request, int $auditId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $auditRun = AuditRun::where('organization_id', $org->id)->where('id', $auditId)->first();

        if (!$auditRun) {
            return $this->error('Audit run not found.', 'AUDIT_NOT_FOUND', null, 404);
        }

        $query = AuditIssue::where('audit_run_id', $auditRun->id);

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('issue_type')) {
            $query->where('issue_type', $request->input('issue_type'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('affected_url', 'like', $term)
                  ->orWhere('explanation', 'like', $term)
                  ->orWhere('issue_type', 'like', $term);
            });
        }

        $perPage = min(500, max(1, $request->integer('per_page', 100)));
        $issues = $query->orderByRaw("CASE WHEN severity = 'critical' THEN 1 WHEN severity = 'warning' THEN 2 ELSE 3 END")
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return $this->success($issues->items(), [
            'current_page' => $issues->currentPage(),
            'per_page' => $issues->perPage(),
            'total' => $issues->total(),
            'last_page' => $issues->lastPage(),
        ]);
    }

    /**
     * Compare two audit runs (Before / After analysis).
     */
    public function compareAudits(Request $request, int $websiteId): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $website = Website::where('organization_id', $org->id)->where('id', $websiteId)->first();

        if (!$website) {
            return $this->error('Website not found.', 'WEBSITE_NOT_FOUND', null, 404);
        }

        $run1Id = $request->input('run1');
        $run2Id = $request->input('run2');

        if (!$run1Id || !$run2Id) {
            $recent = AuditRun::where('website_id', $website->id)->orderBy('id', 'desc')->take(2)->get();
            if ($recent->count() < 2) {
                return $this->error('At least two audit runs are required for comparison.', 'INSUFFICIENT_AUDITS', null, 422);
            }
            $run2 = $recent[0];
            $run1 = $recent[1];
        } else {
            $run1 = AuditRun::where('website_id', $website->id)->where('id', $run1Id)->first();
            $run2 = AuditRun::where('website_id', $website->id)->where('id', $run2Id)->first();

            if (!$run1 || !$run2) {
                return $this->error('One or both audit runs could not be found.', 'AUDIT_NOT_FOUND', null, 404);
            }
        }

        $issues1 = AuditIssue::where('audit_run_id', $run1->id)->get();
        $issues2 = AuditIssue::where('audit_run_id', $run2->id)->get();

        $map1 = [];
        foreach ($issues1 as $i) {
            $key = $i->issue_type . '::' . $i->affected_url;
            $map1[$key] = $i;
        }

        $map2 = [];
        foreach ($issues2 as $i) {
            $key = $i->issue_type . '::' . $i->affected_url;
            $map2[$key] = $i;
        }

        $resolved = [];
        foreach ($map1 as $key => $issue) {
            if (!isset($map2[$key])) {
                $resolved[] = $issue;
            }
        }

        $newIssues = [];
        foreach ($map2 as $key => $issue) {
            if (!isset($map1[$key])) {
                $newIssues[] = $issue;
            }
        }

        $beforePayload = [
            'id' => $run1->id,
            'score' => $run1->overall_score,
            'technical_score' => $run1->technical_score,
            'content_score' => $run1->content_score,
            'indexing_score' => $run1->indexing_score ?? 100,
            'links_score' => $run1->link_score,
            'critical_count' => $run1->critical_count,
            'warning_count' => $run1->warning_count,
            'info_count' => $run1->info_count,
            'date' => $run1->created_at?->toIso8601String(),
        ];

        $afterPayload = [
            'id' => $run2->id,
            'score' => $run2->overall_score,
            'technical_score' => $run2->technical_score,
            'content_score' => $run2->content_score,
            'indexing_score' => $run2->indexing_score ?? 100,
            'links_score' => $run2->link_score,
            'critical_count' => $run2->critical_count,
            'warning_count' => $run2->warning_count,
            'info_count' => $run2->info_count,
            'date' => $run2->created_at?->toIso8601String(),
        ];

        return $this->success([
            'before' => $beforePayload,
            'after' => $afterPayload,
            'run1' => $beforePayload,
            'run2' => $afterPayload,
            'score_diff' => $run2->overall_score - $run1->overall_score,
            'resolved_issues_count' => count($resolved),
            'new_issues_count' => count($newIssues),
            'resolved_issues' => array_values($resolved),
            'new_issues' => array_values($newIssues),
        ]);
    }

    /**
     * Export audit report to CSV or JSON.
     */
    public function exportAudit(Request $request, int $auditId)
    {
        /** @var Organization $org */
        $org = $request->attributes->get('organization');

        $auditRun = AuditRun::with('website')->where('organization_id', $org->id)->where('id', $auditId)->first();

        if (!$auditRun) {
            return $this->error('Audit run not found.', 'AUDIT_NOT_FOUND', null, 404);
        }

        $format = $request->input('format', 'csv');
        $issues = AuditIssue::where('audit_run_id', $auditRun->id)->get();

        if ($format === 'json') {
            return response()->json([
                'audit' => $this->formatAuditRun($auditRun),
                'issues' => $issues,
            ]);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"audit-report-{$auditRun->website->domain}-{$auditRun->id}.csv\"",
        ];

        return new StreamedResponse(function () use ($issues) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Severity', 'Category', 'Issue Type', 'Affected URL', 'Explanation', 'Recommended Fix', 'Evidence']);

            foreach ($issues as $issue) {
                fputcsv($handle, [
                    strtoupper($issue->severity),
                    ucfirst($issue->category),
                    $issue->issue_type,
                    $issue->affected_url,
                    $issue->explanation,
                    $issue->recommended_fix,
                    $issue->evidence ? json_encode($issue->evidence) : '',
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
