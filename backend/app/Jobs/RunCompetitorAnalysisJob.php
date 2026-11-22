<?php

namespace App\Jobs;

use App\Domain\Competitors\Services\AiCompetitorStrategistService;
use App\Domain\Competitors\Services\BacklinkGapService;
use App\Domain\Competitors\Services\ContentGapService;
use App\Domain\Competitors\Services\DomainComparisonService;
use App\Domain\Competitors\Services\KeywordGapService;
use App\Models\CompetitorAnalysisJob;
use App\Models\CompetitorDomain;
use App\Models\Website;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunCompetitorAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 120;

    public function __construct(public int $analysisJobId)
    {
    }

    public function handle(
        DomainComparisonService $comparisonService,
        KeywordGapService $keywordGapService,
        ContentGapService $contentGapService,
        BacklinkGapService $backlinkGapService,
        AiCompetitorStrategistService $strategistService
    ): void {
        $job = CompetitorAnalysisJob::find($this->analysisJobId);
        if (!$job) {
            return;
        }

        $website = Website::find($job->website_id);
        if (!$website) {
            $job->update([
                'status' => CompetitorAnalysisJob::STATUS_FAILED,
                'error_message' => 'Associated website no longer exists.',
                'completed_at' => now(),
            ]);
            return;
        }

        $params = $job->parameters ?? [];
        $country = strtoupper((string) ($params['country'] ?? $website->target_country ?? 'US'));
        $language = strtolower((string) ($params['language'] ?? $website->preferred_language ?? 'en'));
        $forceRefresh = (bool) ($params['force_refresh'] ?? false);
        $competitorIds = $job->competitor_ids ?? [];

        $job->update([
            'status' => CompetitorAnalysisJob::STATUS_RUNNING,
            'progress_percent' => 10,
            'started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $summary = [];

            // 1. Capture target + competitor domain snapshots
            $compQuery = CompetitorDomain::where('website_id', $website->id)->where('is_active', true);
            if (!empty($competitorIds)) {
                $compQuery->whereIn('id', $competitorIds);
            }
            $competitors = $compQuery->limit(5)->get();

            $comparisonService->captureDomainSnapshot($website, $website->domain, true, null, $country, $language, $forceRefresh);
            foreach ($competitors as $comp) {
                $comparisonService->captureDomainSnapshot($website, $comp->domain, false, $comp, $country, $language, $forceRefresh);
            }
            $summary['domains_snapshotted'] = $competitors->count() + 1;
            $job->update(['progress_percent' => 35]);

            // 2. Keyword Gap Sync
            if (in_array($job->job_type, ['full_analysis', 'keyword_gap'], true)) {
                $summary['keyword_gap'] = $keywordGapService->syncKeywordGap($website, $competitorIds ?: null, $country, $language, $forceRefresh);
            }
            $job->update(['progress_percent' => 55]);

            // 3. Content Gap Analysis
            if (in_array($job->job_type, ['full_analysis', 'content_gap'], true)) {
                $cg = $contentGapService->analyze($website);
                $summary['content_gap'] = [
                    'opportunities_found' => $cg['opportunities_found'],
                    'has_target_crawl_evidence' => $cg['has_target_crawl_evidence'],
                ];
            }
            $job->update(['progress_percent' => 70]);

            // 4. Backlink Gap Sync
            if (in_array($job->job_type, ['full_analysis', 'backlink_gap'], true)) {
                $summary['backlink_gap'] = $backlinkGapService->syncBacklinks($website, $competitorIds ?: null, $forceRefresh);
            }
            $job->update(['progress_percent' => 85]);

            // 5. AI Strategy Report
            if (in_array($job->job_type, ['full_analysis', 'ai_strategy'], true)) {
                $report = $strategistService->generateReport($website, $job->initiated_by, $competitorIds, $job->id);
                $summary['strategy_report_id'] = $report->id;
            }

            $job->update([
                'status' => CompetitorAnalysisJob::STATUS_COMPLETED,
                'progress_percent' => 100,
                'result_summary' => $summary,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Competitor analysis job failed', [
                'job_id' => $job->id,
                'website_id' => $website->id,
                'error' => $e->getMessage(),
            ]);

            $job->update([
                'status' => CompetitorAnalysisJob::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
