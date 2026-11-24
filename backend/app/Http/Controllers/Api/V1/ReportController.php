<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ComprehensiveReportService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private ComprehensiveReportService $reportService)
    {
    }

    /**
     * Fetch unified, verified SEO report bundle across all modules for preview & PDF generation.
     */
    public function bundle(Request $request, Website $website): JsonResponse
    {
        $org = $this->authorizeWebsite($request, $website);

        $range = (string) $request->query('range', '28d');
        $sectionsParam = trim((string) $request->query('sections', 'all'));
        $sections = $sectionsParam === '' || $sectionsParam === 'all'
            ? null
            : array_filter(explode(',', $sectionsParam));

        $bundle = $this->reportService->buildBundle($website, $range, $sections);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'report.bundle_generated',
            $website,
            null,
            ['report_id' => $bundle['report_id'], 'range' => $bundle['range']]
        );

        return $this->success($bundle);
    }

    /**
     * Stream a clean RFC-4180 UTF-8 CSV file for any platform dataset.
     */
    public function exportCsv(Request $request, Website $website): Response
    {
        $org = $this->authorizeWebsite($request, $website);

        $dataset = (string) $request->query('dataset', 'full_summary');
        $range = (string) $request->query('range', '28d');

        $export = $this->reportService->buildCsvExport($website, $dataset, $range);

        AuditLog::record(
            $org->id,
            $request->user()->id,
            'report.csv_exported',
            $website,
            null,
            ['dataset' => $dataset, 'range' => $range, 'rows' => $export['rows_count']]
        );

        return response($export['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Export-Rows' => (string) $export['rows_count'],
        ]);
    }

    private function authorizeWebsite(Request $request, Website $website): Organization
    {
        /** @var Organization|null $org */
        $org = $request->attributes->get('organization');
        $orgId = $org?->id ?? $request->header('X-Organization-Id');

        if (!$org || !$orgId || (int) $website->organization_id !== (int) $orgId) {
            abort(403, 'You do not have access to this website.');
        }

        return $org;
    }
}
