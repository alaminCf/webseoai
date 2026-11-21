<?php

namespace App\Domain\Performance;

use App\Models\AuditIssue;
use App\Models\SearchOpportunity;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OpportunityDetector
{
    /**
     * Minimum thresholds to ensure statistically sound conclusions.
     */
    private int $minImpressionsThreshold = 100;
    private int $minClicksThreshold = 15;
    private float $lowCtrBenchmark = 0.025; // 2.5%

    public function __construct(array $config = [])
    {
        $this->minImpressionsThreshold = (int) ($config['min_impressions'] ?? 100);
        $this->minClicksThreshold = (int) ($config['min_clicks'] ?? 15);
        $this->lowCtrBenchmark = (float) ($config['low_ctr_benchmark'] ?? 0.025);
    }

    /**
     * Scan website search performance data and detect actionable SEO opportunities.
     */
    public function detectOpportunities(Website $website, int $daysWindow = 28): Collection
    {
        $currentEnd = now()->subDays(1)->toDateString();
        $currentStart = now()->subDays($daysWindow)->toDateString();

        $previousEnd = now()->subDays($daysWindow + 1)->toDateString();
        $previousStart = now()->subDays(($daysWindow * 2))->toDateString();

        $detected = collect();

        // 1. Detect Low CTR on High-Impression Queries
        $lowCtrOpps = $this->detectLowCtrQueries($website, $currentStart, $currentEnd);
        $detected = $detected->merge($lowCtrOpps);

        // 2. Detect Click Decay (Current vs Previous Period)
        $decayOpps = $this->detectClickDecay($website, $currentStart, $currentEnd, $previousStart, $previousEnd);
        $detected = $detected->merge($decayOpps);

        // 3. Detect Rising Queries
        $risingOpps = $this->detectRisingQueries($website, $currentStart, $currentEnd, $previousStart, $previousEnd);
        $detected = $detected->merge($risingOpps);

        // 4. Detect Keyword/Page Coverage Gaps linked to Audit Findings
        $gapOpps = $this->detectCoverageGaps($website, $currentStart, $currentEnd);
        $detected = $detected->merge($gapOpps);

        return $detected;
    }

    /**
     * Rule 1: High Impressions with Comparatively Low CTR (Striking distance rank 3-20)
     */
    private function detectLowCtrQueries(Website $website, string $start, string $end): Collection
    {
        $records = SearchPerformanceRecord::query()
            ->select('query', 'page')
            ->selectRaw('SUM(clicks) as total_clicks')
            ->selectRaw('SUM(impressions) as total_impressions')
            ->selectRaw('AVG(position) as avg_position')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->groupBy('query', 'page')
            ->having('total_impressions', '>=', $this->minImpressionsThreshold)
            ->having('avg_position', '<=', 20.0)
            ->having('avg_position', '>=', 3.0)
            ->get();

        $opportunities = collect();

        foreach ($records as $rec) {
            $impressions = (int) $rec->total_impressions;
            $clicks = (int) $rec->total_clicks;
            $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
            $pos = round((float) $rec->avg_position, 1);

            if ($ctr <= $this->lowCtrBenchmark) {
                // Expected CTR at this rank could be ~4-8%, calculate estimated click upside
                $targetCtr = max(0.045, $ctr * 2);
                $estimatedUpside = max(5, (int) round($impressions * ($targetCtr - $ctr)));

                $opp = SearchOpportunity::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'type' => SearchOpportunity::TYPE_LOW_CTR,
                        'target_type' => 'query',
                        'target_identifier' => $rec->query,
                    ],
                    [
                        'organization_id' => $website->organization_id,
                        'headline' => "Striking Distance: Low CTR ({$ctr}%) on High-Impression Query \"{$rec->query}\"",
                        'reasoning' => "This query achieved {$impressions} impressions at position {$pos}, but captured only {$clicks} clicks. The current CTR of " . round($ctr * 100, 2) . "% is well below average for position {$pos}.",
                        'recommended_action' => "Optimize the title tag and meta description for {$rec->page} to incorporate \"{$rec->query}\" prominently with compelling search intent call-to-actions.",
                        'impact_score' => min(95, max(60, (int) round(($impressions / 20) + (20 - $pos)))),
                        'metrics_snapshot' => [
                            'query' => $rec->query,
                            'page' => $rec->page,
                            'impressions' => $impressions,
                            'clicks' => $clicks,
                            'ctr' => $ctr,
                            'avg_position' => $pos,
                            'estimated_click_upside' => $estimatedUpside,
                        ],
                        'status' => SearchOpportunity::STATUS_DETECTED,
                        'detected_at' => now(),
                    ]
                );

                $opportunities->push($opp);
            }
        }

        return $opportunities;
    }

    /**
     * Rule 2: Pages or Queries Losing Clicks across comparable periods
     */
    private function detectClickDecay(
        Website $website,
        string $currStart,
        string $currEnd,
        string $prevStart,
        string $prevEnd
    ): Collection {
        // Aggregate current period by page
        $currPages = SearchPerformanceRecord::query()
            ->select('page')
            ->selectRaw('SUM(clicks) as curr_clicks')
            ->selectRaw('SUM(impressions) as curr_impressions')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$currStart, $currEnd])
            ->groupBy('page')
            ->pluck('curr_clicks', 'page');

        // Aggregate previous period by page
        $prevPages = SearchPerformanceRecord::query()
            ->select('page')
            ->selectRaw('SUM(clicks) as prev_clicks')
            ->selectRaw('SUM(impressions) as prev_impressions')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$prevStart, $prevEnd])
            ->groupBy('page')
            ->having('prev_clicks', '>=', $this->minClicksThreshold)
            ->get();

        $opportunities = collect();

        foreach ($prevPages as $prev) {
            $pageUrl = $prev->page;
            $prevClicks = (int) $prev->prev_clicks;
            $currClicks = (int) ($currPages[$pageUrl] ?? 0);

            $loss = $prevClicks - $currClicks;
            $pctDrop = $prevClicks > 0 ? round(($loss / $prevClicks) * 100, 1) : 0.0;

            if ($loss >= 10 && $pctDrop >= 20.0) {
                $opp = SearchOpportunity::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'type' => SearchOpportunity::TYPE_CLICK_DECAY,
                        'target_type' => 'page',
                        'target_identifier' => $pageUrl,
                    ],
                    [
                        'organization_id' => $website->organization_id,
                        'headline' => "Click Decay Alert: {$loss} Clicks Lost (-{$pctDrop}%) on {$pageUrl}",
                        'reasoning' => "Traffic dropped from {$prevClicks} clicks to {$currClicks} clicks (-{$pctDrop}%) over comparable 28-day periods. This indicates content obsolescence, search intent shift, or competitor displacement.",
                        'recommended_action' => "Perform a comprehensive content refresh on {$pageUrl}: update statistics, review competitor changes in the top 3 results, and strengthen internal linking from newer pages.",
                        'impact_score' => min(98, max(75, (int) round(70 + ($loss / 2)))),
                        'metrics_snapshot' => [
                            'page' => $pageUrl,
                            'previous_clicks' => $prevClicks,
                            'current_clicks' => $currClicks,
                            'lost_clicks' => $loss,
                            'percentage_drop' => $pctDrop,
                        ],
                        'status' => SearchOpportunity::STATUS_DETECTED,
                        'detected_at' => now(),
                    ]
                );

                $opportunities->push($opp);
            }
        }

        return $opportunities;
    }

    /**
     * Rule 3: Queries with Improving Visibility (Rising Fast Movers)
     */
    private function detectRisingQueries(
        Website $website,
        string $currStart,
        string $currEnd,
        string $prevStart,
        string $prevEnd
    ): Collection {
        $currQueries = SearchPerformanceRecord::query()
            ->select('query', 'page')
            ->selectRaw('SUM(clicks) as curr_clicks')
            ->selectRaw('SUM(impressions) as curr_impressions')
            ->selectRaw('AVG(position) as curr_pos')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$currStart, $currEnd])
            ->whereNotNull('query')
            ->groupBy('query', 'page')
            ->having('curr_impressions', '>=', 50)
            ->get()
            ->keyBy('query');

        $prevQueries = SearchPerformanceRecord::query()
            ->select('query')
            ->selectRaw('AVG(position) as prev_pos')
            ->selectRaw('SUM(impressions) as prev_imp')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$prevStart, $prevEnd])
            ->whereNotNull('query')
            ->groupBy('query')
            ->get()
            ->keyBy('query');

        $opportunities = collect();

        foreach ($currQueries as $q => $curr) {
            if (!isset($prevQueries[$q])) continue;

            $prevPos = round((float) $prevQueries[$q]->prev_pos, 1);
            $currPos = round((float) $curr->curr_pos, 1);
            $posImprovement = round($prevPos - $currPos, 1); // e.g. 15.0 -> 8.0 = +7.0

            $prevImp = (int) $prevQueries[$q]->prev_imp;
            $currImp = (int) $curr->curr_impressions;
            $impGrowth = $prevImp > 0 ? round((($currImp - $prevImp) / $prevImp) * 100, 1) : 0.0;

            if ($posImprovement >= 3.0 || $impGrowth >= 50.0) {
                $opp = SearchOpportunity::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'type' => SearchOpportunity::TYPE_RISING_QUERY,
                        'target_type' => 'query',
                        'target_identifier' => $q,
                    ],
                    [
                        'organization_id' => $website->organization_id,
                        'headline' => "Rising Query: \"{$q}\" Surged {$posImprovement} Positions to Rank {$currPos}",
                        'reasoning' => "Query visibility accelerated from position {$prevPos} to {$currPos} with impression growth of +{$impGrowth}%. This page is gaining search momentum.",
                        'recommended_action' => "Anchor this upward momentum by expanding depth on \"{$q}\" within {$curr->page} and pointing 2-3 relevant internal links with descriptive anchor text.",
                        'impact_score' => min(90, max(65, (int) round(60 + ($posImprovement * 3)))),
                        'metrics_snapshot' => [
                            'query' => $q,
                            'page' => $curr->page,
                            'previous_position' => $prevPos,
                            'current_position' => $currPos,
                            'position_gain' => $posImprovement,
                            'impression_growth_pct' => $impGrowth,
                        ],
                        'status' => SearchOpportunity::STATUS_DETECTED,
                        'detected_at' => now(),
                    ]
                );

                $opportunities->push($opp);
            }
        }

        return $opportunities;
    }

    /**
     * Rule 4: Pages with relevant keyword coverage gaps & technical audit flaws
     */
    private function detectCoverageGaps(Website $website, string $start, string $end): Collection
    {
        // High impression pages
        $topPages = SearchPerformanceRecord::query()
            ->select('page')
            ->selectRaw('SUM(impressions) as total_imp')
            ->where('website_id', $website->id)
            ->whereBetween('date', [$start, $end])
            ->groupBy('page')
            ->having('total_imp', '>=', $this->minImpressionsThreshold)
            ->get();

        $opportunities = collect();

        foreach ($topPages as $pageRec) {
            $pageUrl = $pageRec->page;
            $impressions = (int) $pageRec->total_imp;

            // Check if there are open audit issues for this page
            $auditIssues = AuditIssue::where('website_id', $website->id)
                ->where('affected_url', $pageUrl)
                ->whereIn('severity', ['critical', 'error', 'warning'])
                ->get();

            if ($auditIssues->isNotEmpty()) {
                $issueList = $auditIssues->map(fn($i) => $i->explanation ?: str_replace('_', ' ', $i->issue_type))->take(2)->implode(', ');

                $opp = SearchOpportunity::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'type' => SearchOpportunity::TYPE_KEYWORD_GAP,
                        'target_type' => 'page',
                        'target_identifier' => $pageUrl,
                    ],
                    [
                        'organization_id' => $website->organization_id,
                        'headline' => "High-Impression Page ({$impressions} imp) Hampered by Technical Issues",
                        'reasoning' => "This page generates high organic visibility ({$impressions} impressions) but has unresolved technical audit issues: {$issueList}.",
                        'recommended_action' => "Resolve the technical audit issues on {$pageUrl} to prevent crawl budget waste and bounce rate spikes from organic search.",
                        'impact_score' => 88,
                        'metrics_snapshot' => [
                            'page' => $pageUrl,
                            'impressions' => $impressions,
                            'audit_issues_count' => $auditIssues->count(),
                            'top_issues' => $issueList,
                        ],
                        'status' => SearchOpportunity::STATUS_DETECTED,
                        'detected_at' => now(),
                    ]
                );

                $opportunities->push($opp);
            }
        }

        return $opportunities;
    }
}
