<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Models\AiRecommendation;
use App\Models\ContentBrief;
use App\Models\KiAlert;
use App\Models\KiAutomationRun;
use App\Models\KiAutomationWorkflow;
use App\Models\KiKeyword;
use App\Models\Website;
use Throwable;

/**
 * Automated Keyword Research & Intelligence Workflow Engine.
 *
 * Orchestrates recurring/manual keyword discovery, incremental intent-safe clustering,
 * opportunity detection, Rank Checker monitoring handoff, draft SEO Action Plan / Content
 * recommendations (NEVER auto-publishes), and real-time workspace alerts.
 */
class KeywordAutomationService
{
    public function __construct(
        private KeywordDiscoveryService $discoveryService,
        private KeywordClusteringService $clusteringService
    ) {
    }

    public function createWorkflow(Website $website, array $data, ?int $userId = null): KiAutomationWorkflow
    {
        $seeds = array_values(array_unique(array_filter(array_map('trim', (array) ($data['seed_keywords'] ?? [])))));
        $frequency = in_array($data['schedule_frequency'] ?? 'weekly', ['daily', 'weekly', 'monthly', 'custom'], true)
            ? $data['schedule_frequency']
            : 'weekly';

        $workflow = new KiAutomationWorkflow([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'created_by_user_id' => $userId,
            'name' => trim((string) ($data['name'] ?? 'Weekly Keyword Discovery')),
            'status' => ($data['status'] ?? 'active') === 'paused' ? KiAutomationWorkflow::STATUS_PAUSED : KiAutomationWorkflow::STATUS_ACTIVE,
            'seed_keywords' => $seeds,
            'business_category' => !empty($data['business_category']) ? trim((string) $data['business_category']) : null,
            'target_country' => strtoupper(trim((string) ($data['target_country'] ?? ($website->target_country ?: 'US')))),
            'target_language' => strtolower(trim((string) ($data['target_language'] ?? ($website->preferred_language ?: 'en')))),
            'discovery_sources' => !empty($data['discovery_sources']) && is_array($data['discovery_sources'])
                ? array_values(array_intersect($data['discovery_sources'], KeywordDiscoveryService::ALL_SOURCES))
                : KeywordDiscoveryService::ALL_SOURCES,
            'schedule_frequency' => $frequency,
            'custom_interval_hours' => $frequency === 'custom' ? max(1, min(720, (int) ($data['custom_interval_hours'] ?? 24))) : null,
            'auto_cluster' => (bool) ($data['auto_cluster'] ?? true),
            'auto_monitor_rank' => (bool) ($data['auto_monitor_rank'] ?? false),
            'auto_content_recommendations' => (bool) ($data['auto_content_recommendations'] ?? true),
            'notify_on_opportunities' => (bool) ($data['notify_on_opportunities'] ?? true),
            'min_opportunity_score' => max(30, min(95, (int) ($data['min_opportunity_score'] ?? 65))),
        ]);

        $workflow->next_run_at = $workflow->status === KiAutomationWorkflow::STATUS_ACTIVE
            ? $workflow->computeNextRunAt(now())
            : null;
        $workflow->save();

        return $workflow;
    }

    public function updateWorkflow(KiAutomationWorkflow $workflow, array $data): KiAutomationWorkflow
    {
        $updatable = [
            'name', 'status', 'seed_keywords', 'business_category', 'target_country',
            'target_language', 'discovery_sources', 'schedule_frequency', 'custom_interval_hours',
            'auto_cluster', 'auto_monitor_rank', 'auto_content_recommendations',
            'notify_on_opportunities', 'min_opportunity_score',
        ];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $workflow->{$field} = $data[$field];
            }
        }

        if ($workflow->status === KiAutomationWorkflow::STATUS_PAUSED) {
            $workflow->next_run_at = null;
        } elseif ($workflow->next_run_at === null) {
            $workflow->next_run_at = $workflow->computeNextRunAt(now());
        }

        $workflow->save();
        return $workflow->fresh();
    }

    /**
     * Execute a workflow run with idempotency protection, incremental clustering,
     * draft recommendation generation, optional Rank Checker handoff, and alerts.
     */
    public function executeWorkflow(
        KiAutomationWorkflow $workflow,
        string $triggerType = 'manual',
        array $options = []
    ): KiAutomationRun {
        // Idempotency guard: avoid overlapping concurrent runs for the same workflow
        $activeRun = KiAutomationRun::where('workflow_id', $workflow->id)
            ->where('status', KiAutomationRun::STATUS_RUNNING)
            ->where('started_at', '>=', now()->subMinutes(5))
            ->first();

        if ($activeRun) {
            return $activeRun;
        }

        $website = $workflow->website;
        $startTime = microtime(true);

        $run = KiAutomationRun::create([
            'organization_id' => $workflow->organization_id,
            'website_id' => $workflow->website_id,
            'workflow_id' => $workflow->id,
            'trigger_type' => $triggerType,
            'status' => KiAutomationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            // Step 1: Multi-source keyword discovery
            $discovery = $this->discoveryService->discover($website, [
                'seed_type' => 'multiple',
                'seed_keywords' => $workflow->seed_keywords ?? [],
                'business_category' => $workflow->business_category,
                'country' => $workflow->target_country,
                'language' => $workflow->target_language,
                'sources' => $workflow->discovery_sources ?? KeywordDiscoveryService::ALL_SOURCES,
                'skip_external_http' => (bool) ($options['skip_external_http'] ?? false),
            ], $workflow->created_by_user_id);

            $discoveredKeywords = collect($discovery['keywords'] ?? []);
            $newCount = (int) ($discovery['new_keywords'] ?? 0);
            $totalDiscovered = (int) ($discovery['total_discovered'] ?? 0);

            // Step 2: Incremental clustering (preserves existing clusters & respects intent separation)
            $clustersUpdated = 0;
            if ($workflow->auto_cluster && $discoveredKeywords->isNotEmpty()) {
                $clustersUpdated = $this->clusteringService->attachIncrementally($website, $discoveredKeywords);
            }

            // Step 3: Detect qualifying high-opportunity keywords
            $minScore = (int) ($workflow->min_opportunity_score ?: 65);
            $opportunities = $discoveredKeywords
                ->filter(fn (KiKeyword $k) => ($k->opportunity_score ?? 0) >= $minScore)
                ->sortByDesc('opportunity_score')
                ->values();

            // Step 4: Draft Content / SEO Action Plan Recommendations (NEVER auto-publishes!)
            $recommendationsCreated = 0;
            if ($workflow->auto_content_recommendations && $opportunities->isNotEmpty()) {
                foreach ($opportunities->take(3) as $oppKw) {
                    // Check for existing ContentBrief or AiRecommendation to avoid duplicates
                    $hasBrief = ContentBrief::where('website_id', $website->id)
                        ->whereRaw('LOWER(target_keyword) = ?', [strtolower($oppKw->keyword)])
                        ->exists();
                    $hasRec = $oppKw->saved_recommendation_id !== null
                        || AiRecommendation::where('website_id', $website->id)
                            ->where('category', 'keyword_opportunity')
                            ->where('title', 'like', '%' . $oppKw->keyword . '%')
                            ->exists();

                    if (!$hasBrief && !$hasRec) {
                        $this->discoveryService->sendToActionPlan($website, $oppKw, $workflow->created_by_user_id);
                        $recommendationsCreated++;
                    }
                }
            }

            // Step 5: Optional Rank Checker monitoring handoff (reuses existing RankCheckerService)
            $monitoredCount = 0;
            if ($workflow->auto_monitor_rank && empty($options['skip_external_http']) && $opportunities->isNotEmpty()) {
                foreach ($opportunities->where('is_monitored', false)->take(2) as $monKw) {
                    $this->discoveryService->monitorInRankChecker($website, $monKw, $workflow->created_by_user_id);
                    $monitoredCount++;
                }
            }

            // Step 6: Workspace Alerts & Notifications
            $alertsCreated = 0;
            if ($workflow->notify_on_opportunities) {
                if ($opportunities->isNotEmpty()) {
                    $topOpp = $opportunities->first();
                    KiAlert::create([
                        'organization_id' => $workflow->organization_id,
                        'website_id' => $workflow->website_id,
                        'workflow_id' => $workflow->id,
                        'keyword_id' => $topOpp->id,
                        'alert_type' => 'high_opportunity',
                        'severity' => ($topOpp->opportunity_score ?? 0) >= 80 ? 'high' : 'info',
                        'title' => "{$opportunities->count()} high-opportunity keyword(s) found by \"{$workflow->name}\"",
                        'message' => "Top opportunity: \"{$topOpp->keyword}\" (Score {$topOpp->opportunity_score}/100, {$topOpp->search_intent} intent). {$topOpp->opportunity_reason}",
                        'evidence' => [
                            'workflow_id' => $workflow->id,
                            'top_keyword' => $topOpp->keyword,
                            'opportunity_score' => $topOpp->opportunity_score,
                            'count' => $opportunities->count(),
                        ],
                    ]);
                    $alertsCreated++;
                }

                if ($newCount > 0) {
                    KiAlert::create([
                        'organization_id' => $workflow->organization_id,
                        'website_id' => $workflow->website_id,
                        'workflow_id' => $workflow->id,
                        'alert_type' => 'new_keywords',
                        'severity' => 'info',
                        'title' => "{$newCount} new keyword(s) discovered",
                        'message' => "Automation \"{$workflow->name}\" discovered {$newCount} net-new keywords across " . implode(', ', $discovery['providers_used'] ?? ['workspace']) . '.',
                        'evidence' => [
                            'new_keywords' => $newCount,
                            'providers_used' => $discovery['providers_used'] ?? [],
                        ],
                    ]);
                    $alertsCreated++;
                }

                $rising = $discoveredKeywords->where('trend_direction', 'rising');
                if ($rising->isNotEmpty()) {
                    $topRising = $rising->first();
                    KiAlert::create([
                        'organization_id' => $workflow->organization_id,
                        'website_id' => $workflow->website_id,
                        'workflow_id' => $workflow->id,
                        'keyword_id' => $topRising->id,
                        'alert_type' => 'trend_shift',
                        'severity' => 'high',
                        'title' => "Rising search trend detected: \"{$topRising->keyword}\"",
                        'message' => "{$rising->count()} keyword(s) show upward historical search demand trends.",
                        'evidence' => ['rising_keywords' => $rising->take(5)->pluck('keyword')->toArray()],
                    ]);
                    $alertsCreated++;
                }
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            $run->update([
                'status' => KiAutomationRun::STATUS_COMPLETED,
                'keywords_discovered' => $totalDiscovered,
                'new_keywords' => $newCount,
                'clusters_updated' => $clustersUpdated,
                'opportunities_found' => $opportunities->count(),
                'alerts_created' => $alertsCreated,
                'content_recommendations_created' => $recommendationsCreated,
                'duration_ms' => $durationMs,
                'summary' => [
                    'providers_used' => $discovery['providers_used'] ?? [],
                    'provider_errors' => $discovery['provider_errors'] ?? [],
                    'monitored_keywords_added' => $monitoredCount,
                    'top_opportunity_keywords' => $opportunities->take(5)->pluck('keyword')->toArray(),
                ],
                'completed_at' => now(),
            ]);

            $workflow->update([
                'last_run_at' => now(),
                'next_run_at' => $workflow->status === KiAutomationWorkflow::STATUS_ACTIVE
                    ? $workflow->computeNextRunAt(now())
                    : null,
                'total_keywords_discovered' => $workflow->total_keywords_discovered + $newCount,
                'total_opportunities_found' => $workflow->total_opportunities_found + $opportunities->count(),
                'last_error' => null,
            ]);

            return $run->fresh();
        } catch (Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $run->update([
                'status' => KiAutomationRun::STATUS_FAILED,
                'duration_ms' => $durationMs,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);

            $workflow->update([
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'next_run_at' => $workflow->status === KiAutomationWorkflow::STATUS_ACTIVE
                    ? now()->addHours(2)
                    : null,
            ]);

            return $run->fresh();
        }
    }
}
