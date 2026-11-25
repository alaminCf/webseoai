<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Models\CompetitorSerpSnapshot;
use App\Models\KiAlert;
use App\Models\KiCannibalizationFinding;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;

/**
 * Evidence-Based Keyword Cannibalization Detection Engine.
 *
 * Analyzes first-party Google Search Console query×page data and Rank Checker multi-URL matches.
 * Strictly avoids labeling normal low-volume multi-URL visibility (e.g. sitelinks or 1-impression noise)
 * as definite cannibalization by enforcing impression share, click split, and position spread thresholds.
 */
class KeywordCannibalizationService
{
    private const MIN_TOTAL_IMPRESSIONS = 25;
    private const MIN_SECONDARY_IMPRESSION_SHARE = 0.15; // Secondary URL must capture >= 15% of impressions

    /**
     * Run cannibalization detection for a website over the last 90 days of GSC data + SERP snapshots.
     */
    public function analyze(Website $website, int $days = 90): array
    {
        $since = now()->subDays(max(14, min(180, $days)))->toDateString();

        $rows = SearchPerformanceRecord::where('website_id', $website->id)
            ->whereDate('date', '>=', $since)
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->whereNotNull('page')
            ->where('page', '!=', '')
            ->select('query', 'page')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_pos, COUNT(DISTINCT date) as active_days')
            ->groupBy('query', 'page')
            ->get();

        // Group by normalized query
        $byQuery = [];
        foreach ($rows as $row) {
            $qNorm = strtolower(trim((string) $row->query));
            $url = rtrim(trim((string) $row->page), '/');
            if ($qNorm === '' || $url === '') {
                continue;
            }
            if (!isset($byQuery[$qNorm])) {
                $byQuery[$qNorm] = [
                    'keyword' => (string) $row->query,
                    'urls' => [],
                ];
            }
            if (!isset($byQuery[$qNorm]['urls'][$url])) {
                $byQuery[$qNorm]['urls'][$url] = [
                    'url' => $url,
                    'clicks' => 0,
                    'impressions' => 0,
                    'weighted_pos' => 0.0,
                    'active_days' => 0,
                ];
            }
            $imps = (int) $row->impressions;
            $byQuery[$qNorm]['urls'][$url]['clicks'] += (int) $row->clicks;
            $byQuery[$qNorm]['urls'][$url]['impressions'] += $imps;
            $byQuery[$qNorm]['urls'][$url]['weighted_pos'] += ((float) $row->avg_pos) * $imps;
            $byQuery[$qNorm]['urls'][$url]['active_days'] += (int) $row->active_days;
        }

        // Also check Rank Checker snapshots where target domain appeared with >= 2 distinct URLs on the same SERP
        $serpMultiUrlQueries = [];
        $snapshots = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->orderByDesc('captured_at')
            ->limit(100)
            ->get();

        foreach ($snapshots as $snap) {
            $targetUrls = [];
            foreach (is_array($snap->organic_results) ? $snap->organic_results : [] as $item) {
                if (!empty($item['is_target']) && !empty($item['url'])) {
                    $targetUrls[] = [
                        'url' => rtrim((string) $item['url'], '/'),
                        'position' => (int) ($item['position'] ?? 0),
                    ];
                }
            }
            if (count($targetUrls) >= 2) {
                $serpMultiUrlQueries[strtolower(trim($snap->keyword))] = $targetUrls;
            }
        }

        $findings = [];
        $evaluatedQueries = count($byQuery);

        foreach ($byQuery as $qNorm => $data) {
            $urlList = array_values($data['urls']);
            if (count($urlList) < 2) {
                continue;
            }

            // Compute totals & per-URL average position
            $totalImpressions = 0;
            $totalClicks = 0;
            foreach ($urlList as &$u) {
                $u['avg_position'] = $u['impressions'] > 0
                    ? round($u['weighted_pos'] / $u['impressions'], 1)
                    : 0.0;
                $u['ctr'] = $u['impressions'] > 0 ? round(($u['clicks'] / $u['impressions']) * 100, 2) : 0.0;
                unset($u['weighted_pos']);
                $totalImpressions += $u['impressions'];
                $totalClicks += $u['clicks'];
            }
            unset($u);

            // Evidence gate #1: ignore very low volume queries (avoid false positives)
            if ($totalImpressions < self::MIN_TOTAL_IMPRESSIONS) {
                continue;
            }

            usort($urlList, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

            $primary = $urlList[0];
            $secondary = $urlList[1];
            $secondaryShare = $totalImpressions > 0 ? ($secondary['impressions'] / $totalImpressions) : 0.0;

            // Evidence gate #2: secondary URL must hold meaningful impression share OR genuine click split
            if ($secondaryShare < self::MIN_SECONDARY_IMPRESSION_SHARE && $secondary['clicks'] < 2) {
                continue;
            }

            // Calculate position spread & best position
            $positions = array_filter(array_column($urlList, 'avg_position'), fn ($p) => $p > 0);
            $bestPos = !empty($positions) ? min($positions) : null;
            $worstPos = !empty($positions) ? max($positions) : null;
            $spread = ($bestPos !== null && $worstPos !== null) ? round($worstPos - $bestPos, 1) : 0.0;

            // Avoid flagging benign Top-2 double-listing (e.g. #1 and #2 sitelink dominance with strong CTR)
            if ($bestPos !== null && $bestPos <= 2.5 && $spread <= 2.0 && $primary['clicks'] >= 10 && $secondary['clicks'] <= 2) {
                continue;
            }

            $inLiveSerp = isset($serpMultiUrlQueries[$qNorm]);

            // Calibrate confidence transparently based on strength of empirical evidence
            $confidence = 'low';
            if (
                ($totalImpressions >= 150 && $secondaryShare >= 0.25 && $secondary['clicks'] >= 3) ||
                ($totalImpressions >= 100 && $spread >= 5.0 && $secondaryShare >= 0.25) ||
                ($inLiveSerp && $bestPos !== null && $bestPos > 3.5)
            ) {
                $confidence = 'high';
            } elseif ($totalImpressions >= 50 && $secondaryShare >= 0.20) {
                $confidence = 'medium';
            }

            foreach ($urlList as &$u) {
                $u['impression_share_pct'] = $totalImpressions > 0 ? round(($u['impressions'] / $totalImpressions) * 100, 1) : 0.0;
            }
            unset($u);

            $secPct = round($secondaryShare * 100, 1);
            $evidenceSummary = count($urlList) . " URLs split {$totalImpressions} GSC impressions ({$totalClicks} clicks) over {$days} days. Secondary URL captures {$secPct}% share"
                . ($spread > 0 ? " with a {$spread}-position spread" : '')
                . ($inLiveSerp ? ' and multiple URLs also appeared on the same live SERP.' : '.');

            $resolution = $this->buildResolution($primary, $secondary, $bestPos, $spread);

            $finding = KiCannibalizationFinding::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'keyword' => $data['keyword'],
                ],
                [
                    'organization_id' => $website->organization_id,
                    'competing_urls' => array_slice($urlList, 0, 5),
                    'total_impressions' => $totalImpressions,
                    'total_clicks' => $totalClicks,
                    'best_position' => $bestPos,
                    'position_spread' => $spread,
                    'confidence' => $confidence,
                    'evidence_summary' => $evidenceSummary,
                    'suggested_resolution' => $resolution,
                    'detected_at' => now(),
                ]
            );

            $findings[] = $finding;
        }

        // Create high-severity alert if new high-confidence cannibalization cases were found
        $highConf = collect($findings)->where('confidence', 'high');
        if ($highConf->isNotEmpty()) {
            $topFinding = $highConf->first();
            $recentAlertExists = KiAlert::where('website_id', $website->id)
                ->where('alert_type', 'cannibalization')
                ->where('created_at', '>=', now()->subDay())
                ->exists();
            if (!$recentAlertExists) {
                KiAlert::create([
                    'organization_id' => $website->organization_id,
                    'website_id' => $website->id,
                    'alert_type' => 'cannibalization',
                    'severity' => 'high',
                    'title' => "Keyword cannibalization detected on {$highConf->count()} high-confidence query(ies)",
                    'message' => "Top conflict: \"{$topFinding->keyword}\" — {$topFinding->evidence_summary}",
                    'evidence' => [
                        'keyword' => $topFinding->keyword,
                        'competing_urls' => $topFinding->competing_urls,
                        'confidence' => $topFinding->confidence,
                    ],
                ]);
            }
        }

        return [
            'evaluated_queries' => $evaluatedQueries,
            'findings_count' => count($findings),
            'high_confidence_count' => $highConf->count(),
            'evidence_policy' => 'Requires >= 25 combined GSC impressions and >= 15% secondary URL share (or >= 2 secondary clicks); benign Top-2 double listings are excluded.',
            'findings' => $findings,
        ];
    }

    private function buildResolution(array $primary, array $secondary, ?float $bestPos, float $spread): string
    {
        if ($bestPos !== null && $bestPos > 6.0) {
            return "Consolidate overlapping content from {$secondary['url']} into primary page {$primary['url']} (301 redirect or rel=canonical) and point internal anchor links exclusively to the primary URL to concentrate ranking authority.";
        }
        if ($spread >= 6.0) {
            return "Differentiate search intent: keep {$primary['url']} optimized for the primary head query, and retarget {$secondary['url']} (title, H1, internal anchors) toward a distinct long-tail sub-topic.";
        }
        return "Audit both {$primary['url']} and {$secondary['url']} for overlapping H1/title tags; strengthen canonical signals and internal link consistency toward {$primary['url']}.";
    }
}
