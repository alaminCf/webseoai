<?php

namespace App\Domain\Competitors\Services;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\AiRecommendation;
use App\Models\CompetitorBacklink;
use App\Models\CompetitorContentGap;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\CompetitorSerpSnapshot;
use App\Models\CompetitorStrategyReport;
use App\Models\Website;
use Illuminate\Support\Str;

class AiCompetitorStrategistService
{
    public function __construct(
        private DomainComparisonService $comparisonService,
        private BacklinkGapService $backlinkGapService
    ) {
    }

    /**
     * Synthesize a structured, strictly evidence-grounded AI Competitor Strategy Report.
     * Clearly separates observed data from strategic interpretation and NEVER invents competitor metrics.
     */
    public function generateReport(Website $website, ?int $userId = null, array $competitorIds = [], ?int $jobId = null): CompetitorStrategyReport
    {
        $comparison = $this->comparisonService->buildComparison($website, $competitorIds);
        $competitors = CompetitorDomain::where('website_id', $website->id)->where('is_active', true)->get();

        $topKeywords = CompetitorKeyword::where('website_id', $website->id)
            ->whereIn('gap_type', [CompetitorKeyword::GAP_MISSING, CompetitorKeyword::GAP_WEAK, CompetitorKeyword::GAP_UNTAPPED])
            ->orderByDesc('opportunity_score')
            ->limit(10)
            ->get();

        $topContentGaps = CompetitorContentGap::where('website_id', $website->id)
            ->where('status', 'open')
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->limit(8)
            ->get();

        $backlinkReport = $this->backlinkGapService->getBacklinkGapReport($website);
        $topBacklinkGaps = array_slice($backlinkReport['items'] ?? [], 0, 8);

        $recentSerps = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->orderByDesc('captured_at')
            ->limit(8)
            ->get();

        $dataSourcesUsed = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($d) => $d['data_source'] ?? null, $comparison['domains']),
            $topKeywords->pluck('data_source')->toArray(),
            $topContentGaps->pluck('data_source')->toArray(),
            $recentSerps->pluck('data_source')->toArray()
        ), fn ($s) => $s && $s !== 'unscanned')));

        if (empty($dataSourcesUsed)) {
            $dataSourcesUsed = ['first_party_crawl'];
        }

        // Build structured deterministic evidence-grounded report first (guaranteed 100% factual)
        $reportData = $this->synthesizeFromRetrievedEvidence(
            $website,
            $comparison,
            $topKeywords,
            $topContentGaps,
            $topBacklinkGaps,
            $recentSerps
        );
        $providerModel = 'evidence-competitor-strategist-v1';

        // Optionally enhance executive summary with external LLM if configured
        $driver = strtolower((string) config('ai.default_driver', 'deterministic_studio'));
        $hasLiveLlm = ($driver === 'gemini' && !empty(config('ai.gemini.api_key')))
            || ($driver === 'openai' && !empty(config('ai.openai.api_key')));

        if ($hasLiveLlm) {
            try {
                $ai = new AiManager(null, $website->organization, $website, $userId);
                $safeEvidence = SecurityPromptWrapper::wrapUntrusted(json_encode([
                    'target_domain' => $website->domain,
                    'comparison' => $comparison['insights'],
                    'keyword_gaps_count' => $topKeywords->count(),
                    'content_gaps_count' => $topContentGaps->count(),
                    'backlink_gaps_count' => count($topBacklinkGaps),
                    'serp_snapshots_count' => $recentSerps->count(),
                ], JSON_PRETTY_PRINT), 'competitor_evidence');

                $summary = $ai->generateText(
                    'You are a Senior Technical SEO Strategist. Write a concise 3-sentence executive summary strictly grounded in the observed evidence. Never invent metrics or guarantee rankings. Distinguish observed metrics from strategic recommendations.',
                    $safeEvidence,
                    'competitor_strategy'
                );
                if (trim($summary) !== '' && !str_starts_with(trim($summary), '{')) {
                    $reportData['executive_summary'] = trim($summary);
                    $providerModel = $driver;
                }
            } catch (\Throwable) {
                // Keep deterministic grounded summary on external LLM error
            }
        }

        return CompetitorStrategyReport::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'generated_by' => $userId,
            'analysis_job_id' => $jobId,
            'competitor_ids' => $competitors->pluck('id')->values()->toArray(),
            'executive_summary' => $reportData['executive_summary'],
            'strength_analysis' => $reportData['strength_analysis'],
            'weakness_analysis' => $reportData['weakness_analysis'],
            'keyword_opportunities' => $reportData['keyword_opportunities'],
            'content_opportunities' => $reportData['content_opportunities'],
            'backlink_opportunities' => $reportData['backlink_opportunities'],
            'serp_opportunities' => $reportData['serp_opportunities'],
            'strategic_recommendations' => $reportData['strategic_recommendations'],
            'provider_model' => $providerModel,
            'data_sources_used' => $dataSourcesUsed,
            'generated_at' => now(),
        ]);
    }

    /**
     * Save a specific strategic recommendation from a report into SEO Action Plan (ai_recommendations).
     */
    public function saveRecommendationToActionPlan(Website $website, CompetitorStrategyReport $report, int $index): AiRecommendation
    {
        $recs = $report->strategic_recommendations ?? [];
        if (!isset($recs[$index])) {
            throw new \InvalidArgumentException("Recommendation index [{$index}] not found on strategy report.");
        }

        $item = $recs[$index];
        $priority = strtolower((string) ($item['priority'] ?? 'medium'));
        $impact = match ($priority) {
            'critical' => 10,
            'high' => 8,
            'medium' => 6,
            default => 4,
        };
        $effortStr = strtolower((string) ($item['effort'] ?? 'medium'));
        $effortScore = $effortStr === 'low' ? 2 : ($effortStr === 'high' ? 7 : 4);
        $confidenceStr = strtolower((string) ($item['confidence'] ?? 'medium'));
        $confidenceScore = $confidenceStr === 'high' ? 9 : ($confidenceStr === 'low' ? 5 : 7);

        $rec = AiRecommendation::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'title' => Str::limit((string) ($item['title'] ?? 'Competitor SEO Opportunity'), 240, ''),
            'category' => $item['category'] ?? 'content',
            'affected_url' => (string) ($item['source_url'] ?? $website->target_url),
            'evidence' => [
                'source' => 'competitor_ai_strategy',
                'strategy_report_id' => $report->id,
                'supporting_evidence' => $item['supporting_evidence'] ?? '',
                'observed_vs_interpreted' => $item['observed_vs_interpreted'] ?? null,
            ],
            'recommended_action' => (string) ($item['next_action'] ?? $item['explanation'] ?? ''),
            'reasoning_summary' => (string) ($item['explanation'] ?? ''),
            'expected_benefit' => (string) ($item['expected_benefit'] ?? 'Estimated competitive visibility gain based on retrieved evidence.'),
            'confidence_level' => in_array($confidenceStr, ['high', 'medium', 'low'], true) ? $confidenceStr : 'medium',
            'effort' => in_array($effortStr, ['high', 'medium', 'low'], true) ? $effortStr : 'medium',
            'risk_level' => 'low',
            'required_permissions' => 'editor',
            'verification_procedure' => 'Re-run Competitor Analysis & Site Audit after publishing changes to verify ranking and crawl improvements.',
            'impact_score' => $impact,
            'confidence_score' => $confidenceScore,
            'effort_score' => $effortScore,
            'risk_score' => 1,
            'priority_score' => AiRecommendation::calculatePriority($impact, $confidenceScore, $effortScore, 1),
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);

        // Mark saved on report JSON
        $recs[$index]['saved_recommendation_id'] = $rec->id;
        $report->update(['strategic_recommendations' => $recs]);

        return $rec;
    }

    private function synthesizeFromRetrievedEvidence(
        Website $website,
        array $comparison,
        $topKeywords,
        $topContentGaps,
        array $topBacklinkGaps,
        $recentSerps
    ): array {
        $peerCount = max(0, count($comparison['domains'] ?? []) - 1);
        $strengths = $comparison['insights']['strengths'] ?? [];
        $weaknesses = $comparison['insights']['weaknesses'] ?? [];

        $kwOpps = [];
        $contentOpps = [];
        $blOpps = [];
        $serpOpps = [];
        $recommendations = [];

        // 1. Keyword Opportunities -> Strategic Recommendations
        foreach ($topKeywords->take(4) as $kw) {
            $bestComp = null;
            $bestPos = null;
            foreach ($kw->competitor_positions ?? [] as $d => $info) {
                $p = is_array($info) ? ($info['position'] ?? null) : null;
                if ($p !== null && ($bestPos === null || $p < $bestPos)) {
                    $bestPos = $p;
                    $bestComp = $d;
                }
            }
            $evidenceLine = "Observed ({$kw->data_source}): \"{$kw->keyword}\" is classified as {$kw->gap_type}" .
                ($kw->target_position ? " (target #{$kw->target_position})" : ' (target not ranking)') .
                ($bestComp ? " vs {$bestComp} (#{$bestPos})" : '') .
                ($kw->search_volume !== null ? ", est. monthly volume {$kw->search_volume}" : '') . '.';

            $kwOpps[] = [
                'keyword' => $kw->keyword,
                'gap_type' => $kw->gap_type,
                'evidence' => $evidenceLine,
                'data_source' => $kw->data_source,
            ];

            $recommendations[] = [
                'title' => "Close " . ucfirst($kw->gap_type) . " Keyword Gap: \"{$kw->keyword}\"",
                'category' => 'content',
                'explanation' => $evidenceLine . ' Strategic interpretation: targeting this query with intent-aligned content can capture incremental search share.',
                'supporting_evidence' => $evidenceLine,
                'source_url' => $kw->target_url ?: $website->target_url,
                'expected_benefit' => 'Estimated uplift in non-branded organic clicks (estimates only; rankings are not guaranteed).',
                'priority' => $kw->gap_type === CompetitorKeyword::GAP_WEAK ? 'critical' : 'high',
                'effort' => $kw->gap_type === CompetitorKeyword::GAP_WEAK ? 'low' : 'medium',
                'confidence' => 'high',
                'next_action' => $kw->gap_type === CompetitorKeyword::GAP_WEAK
                    ? "Refresh page title, H1/H2 structure, and internal anchors targeting \"{$kw->keyword}\"."
                    : "Publish a dedicated page or guide targeting \"{$kw->keyword}\" matching {$kw->search_intent} intent.",
                'observed_vs_interpreted' => [
                    'observed' => $evidenceLine,
                    'interpreted' => 'Competitor ranking proves active search demand on this topic.',
                ],
            ];
        }

        // 2. Content Opportunities
        foreach ($topContentGaps->take(3) as $gap) {
            $ref = $gap->competitor_reference_urls[0]['url'] ?? $website->target_url;
            $contentOpps[] = [
                'topic' => $gap->topic,
                'suggested_title' => $gap->suggested_title,
                'coverage_status' => $gap->target_coverage_status,
                'reference_url' => $ref,
            ];
            $recommendations[] = [
                'title' => "Publish Missing Topic: {$gap->topic}",
                'category' => 'content',
                'explanation' => $gap->rationale,
                'supporting_evidence' => "Competitor URL: {$ref} | Target crawl status: {$gap->target_coverage_status}.",
                'source_url' => $ref,
                'expected_benefit' => 'Expands topical authority and captures competitor-validated organic demand.',
                'priority' => $gap->priority === 'critical' ? 'critical' : ($gap->priority === 'high' ? 'high' : 'medium'),
                'effort' => 'medium',
                'confidence' => $gap->confidence,
                'next_action' => "Send \"{$gap->suggested_title}\" to Content Studio and draft using the recommended outline.",
                'observed_vs_interpreted' => [
                    'observed' => "Competitor publishes on {$ref}; target coverage state is {$gap->target_coverage_status}.",
                    'interpreted' => "Creating a {$gap->suggested_content_type} addresses an evidenced content gap.",
                ],
            ];
        }

        // 3. Backlink Opportunities
        foreach (array_slice($topBacklinkGaps, 0, 3) as $bl) {
            $blOpps[] = [
                'referring_domain' => $bl['referring_domain'],
                'competitors_count' => $bl['competitors_count'],
                'authority' => $bl['referring_authority'],
                'data_source' => $bl['data_source'],
            ];
            $recommendations[] = [
                'title' => "Outreach Opportunity: Earn Backlink from {$bl['referring_domain']}",
                'category' => 'links',
                'explanation' => "Observed ({$bl['data_source']}): {$bl['referring_domain']} links to {$bl['competitors_count']} tracked competitor(s) but does not link to {$website->domain}.",
                'supporting_evidence' => "Referring domain: {$bl['referring_domain']} | Links to: " . implode(', ', $bl['competitors_linked'] ?? []),
                'source_url' => $bl['sample_referring_url'] ?: "https://{$bl['referring_domain']}",
                'expected_benefit' => 'Strengthens referring domain diversity and competitive off-page authority.',
                'priority' => ($bl['competitors_count'] ?? 1) >= 2 ? 'high' : 'medium',
                'effort' => 'medium',
                'confidence' => 'high',
                'next_action' => "Inspect {$bl['sample_referring_url']} and pitch a relevant resource, partnership, or editorial mention for {$website->domain}.",
                'observed_vs_interpreted' => [
                    'observed' => "{$bl['referring_domain']} links to tracked competitors but not {$website->domain}.",
                    'interpreted' => 'High-likelihood outreach prospect since it already links to sites in your niche.',
                ],
            ];
        }

        // 4. SERP Opportunities
        foreach ($recentSerps->take(3) as $serp) {
            $serpOpps[] = [
                'keyword' => $serp->keyword,
                'target_position' => $serp->target_position,
                'serp_features' => $serp->serp_features ?? [],
                'dominant_format' => $serp->content_patterns['dominant_format'] ?? null,
            ];
            if ($serp->target_position === null || $serp->target_position > 5) {
                $recommendations[] = [
                    'title' => "Align Content Format with SERP Leaders for \"{$serp->keyword}\"",
                    'category' => 'architecture',
                    'explanation' => "Observed ({$serp->data_source} snapshot): Top organic results favor " . ($serp->content_patterns['dominant_format'] ?? 'structured pages') . ' with SERP features: ' . (implode(', ', $serp->serp_features ?? []) ?: 'standard organic') . '.',
                    'supporting_evidence' => "SERP Snapshot #{$serp->id} ({$serp->country}/{$serp->device}), target rank: " . ($serp->target_position ?? 'Not in top 20'),
                    'source_url' => $website->target_url,
                    'expected_benefit' => 'Improves SERP format alignment and eligibility for rich SERP features.',
                    'priority' => 'medium',
                    'effort' => 'medium',
                    'confidence' => 'medium',
                    'next_action' => "Mirror winning format (" . ($serp->content_patterns['dominant_format'] ?? 'guide') . ") and add structured FAQ/Schema where relevant.",
                    'observed_vs_interpreted' => [
                        'observed' => "SERP snapshot captured {$serp->captured_at?->toDateString()} shows competitor presence.",
                        'interpreted' => 'Matching dominant SERP format reduces intent mismatch.',
                    ],
                ];
            }
        }

        // 5. Technical/First-Party Weaknesses from Domain Comparison (always grounded even with 0 external keys)
        foreach ($weaknesses as $w) {
            $recommendations[] = [
                'title' => "Address Competitive Gap: {$w['metric']}",
                'category' => 'technical',
                'explanation' => $w['observation'],
                'supporting_evidence' => $w['observation'] . " ({$w['classification']})",
                'source_url' => $website->target_url,
                'expected_benefit' => 'Closes measurable structural and on-page gaps observed against tracked competitors.',
                'priority' => 'high',
                'effort' => 'low',
                'confidence' => 'high',
                'next_action' => "Audit {$website->domain} against tracked competitors in Domain Comparison and apply technical/content upgrades.",
                'observed_vs_interpreted' => [
                    'observed' => $w['observation'],
                    'interpreted' => 'Bringing target site to parity removes competitive disadvantages.',
                ],
            ];
        }

        $execSummary = "Competitor analysis for {$website->domain} evaluated {$peerCount} tracked competitor(s), " .
            count($kwOpps) . ' prioritized keyword gap(s), ' .
            count($contentOpps) . ' content gap opportunity(ies), and ' .
            count($blOpps) . ' referring-domain outreach prospect(s). ' .
            (empty($recommendations)
                ? 'Add competitors and run a competitor analysis (or connect DataForSEO/Semrush/SerpApi) to generate deeper evidence-backed recommendations.'
                : 'Every recommendation below is strictly tied to retrieved first-party or provider-backed evidence, clearly separating observed data from strategic interpretation.');

        return [
            'executive_summary' => $execSummary,
            'strength_analysis' => $strengths,
            'weakness_analysis' => $weaknesses,
            'keyword_opportunities' => $kwOpps,
            'content_opportunities' => $contentOpps,
            'backlink_opportunities' => $blOpps,
            'serp_opportunities' => $serpOpps,
            'strategic_recommendations' => $recommendations,
        ];
    }
}
