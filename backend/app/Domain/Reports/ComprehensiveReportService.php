<?php

namespace App\Domain\Reports;

use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\Traffic\GoogleAnalyticsService;
use App\Domain\Traffic\LiveTrafficService;
use App\Models\AiRecommendation;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CompetitorContentGap;
use App\Models\CompetitorDomain;
use App\Models\CompetitorKeyword;
use App\Models\CompetitorSerpSnapshot;
use App\Models\ContentArticle;
use App\Models\ContentBrief;
use App\Models\CrawledPage;
use App\Models\SearchOpportunity;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;
use App\Models\WebsiteIntegration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Unified, verified platform-wide Reporting & Export Service.
 * Aggregates real data across all 8 SEO modules for executive PDF reports & clean RFC-4180 CSV exports.
 */
class ComprehensiveReportService
{
    public const ALL_SECTIONS = [
        'executive_summary',
        'site_audit',
        'action_plan',
        'search_console',
        'rank_checker',
        'competitors',
        'live_traffic',
        'content_studio',
    ];

    public const CSV_DATASETS = [
        'full_summary',
        'audit_issues',
        'crawled_pages',
        'action_plan',
        'gsc_queries',
        'gsc_pages',
        'search_opportunities',
        'rank_checker',
        'competitors',
        'keyword_gap',
        'content_gap',
        'live_traffic',
        'content_studio',
    ];

    public function __construct(
        private LiveTrafficService $liveTrafficService,
        private GoogleAnalyticsService $ga4Service
    ) {
    }

    /**
     * Build the full structured report bundle for PDF rendering & on-screen preview.
     */
    public function buildBundle(Website $website, string $range = '28d', ?array $requestedSections = null): array
    {
        $range = $this->normalizeRange($range);
        $since = $this->resolveStartDate($range);
        $sections = $this->normalizeSections($requestedSections);

        $org = $website->organization;
        $branding = $org ? $org->getWhiteLabelSettings() : [];
        $clientWorkspace = $website->clientWorkspace;

        // Gather all requested datasets
        $auditData = $this->collectSiteAudit($website);
        $actionPlanData = $this->collectActionPlan($website);
        $gscData = $this->collectSearchConsole($website, $since);
        $rankData = $this->collectRankChecker($website);
        $competitorData = $this->collectCompetitors($website);
        $trafficData = $this->collectTraffic($website, $range);
        $contentData = $this->collectContentStudio($website);

        $executiveSummary = [
            'overall_health_score' => $auditData['overall_score'],
            'technical_score' => $auditData['technical_score'],
            'content_score' => $auditData['content_score'],
            'link_score' => $auditData['link_score'],
            'indexing_score' => $auditData['indexing_score'],
            'pages_crawled' => $auditData['pages_crawled'],
            'open_issues_total' => $auditData['counts']['total_open'],
            'critical_issues' => $auditData['counts']['critical'],
            'warning_issues' => $auditData['counts']['warning'],
            'info_issues' => $auditData['counts']['info'],
            'gsc_clicks' => $gscData['summary']['clicks'],
            'gsc_impressions' => $gscData['summary']['impressions'],
            'gsc_ctr' => $gscData['summary']['ctr'],
            'gsc_avg_position' => $gscData['summary']['avg_position'],
            'gsc_queries_count' => count($gscData['queries']),
            'opportunities_count' => count($gscData['opportunities']),
            'action_plan_total' => $actionPlanData['counts']['total'],
            'action_plan_applied' => $actionPlanData['counts']['applied'],
            'action_plan_pending' => $actionPlanData['counts']['pending'],
            'rank_checks_count' => count($rankData['checks']),
            'rank_top10_count' => $rankData['summary']['in_top_10'],
            'competitors_count' => count($competitorData['competitors']),
            'keyword_gaps_count' => count($competitorData['keyword_gaps']),
            'content_gaps_count' => count($competitorData['content_gaps']),
            'traffic_source' => $trafficData['source'],
            'traffic_visitors' => $trafficData['summary']['unique_visitors'],
            'traffic_pageviews' => $trafficData['summary']['pageviews'],
            'traffic_active_now' => $trafficData['summary']['active_visitors_now'],
            'traffic_top_country' => $trafficData['summary']['top_country'],
            'content_articles_count' => count($contentData['articles']),
            'content_briefs_count' => count($contentData['briefs']),
        ];

        $bundle = [
            'report_id' => 'SEO-' . strtoupper(substr(md5($website->id . '-' . now()->toIso8601String()), 0, 10)),
            'generated_at' => now()->toIso8601String(),
            'range' => $range,
            'range_label' => $this->rangeLabel($range),
            'sections_included' => $sections,
            'website' => [
                'id' => $website->id,
                'name' => $website->name,
                'domain' => $website->domain,
                'target_url' => $website->target_url,
                'platform' => $website->platform,
                'target_country' => $website->target_country ?: 'US',
                'target_country_name' => CountryCatalog::name($website->target_country ?: 'US'),
                'preferred_language' => $website->preferred_language ?: 'en',
                'verification_status' => $website->verification_status,
            ],
            'client' => $clientWorkspace ? [
                'name' => $clientWorkspace->name,
                'company_name' => $clientWorkspace->company_name,
                'contact_person' => $clientWorkspace->contact_person,
            ] : null,
            'branding' => [
                'agency_name' => $branding['brand_name'] ?? ($org?->name ?: 'Web SEO AI'),
                'agency_logo_url' => $branding['logo_url'] ?? null,
                'theme_color' => $branding['primary_color'] ?? '#10b981',
                'contact_email' => $branding['contact_email'] ?? null,
                'footer_text' => $branding['footer_text'] ?? 'Confidential SEO Performance, Technical Audit & Growth Report',
                'hide_platform_badges' => (bool) ($branding['remove_platform_branding'] ?? false),
            ],
            'executive_summary' => $executiveSummary,
        ];

        if (in_array('site_audit', $sections, true)) {
            $bundle['site_audit'] = $auditData;
        }
        if (in_array('action_plan', $sections, true)) {
            $bundle['action_plan'] = $actionPlanData;
        }
        if (in_array('search_console', $sections, true)) {
            $bundle['search_console'] = $gscData;
        }
        if (in_array('rank_checker', $sections, true)) {
            $bundle['rank_checker'] = $rankData;
        }
        if (in_array('competitors', $sections, true)) {
            $bundle['competitors'] = $competitorData;
        }
        if (in_array('live_traffic', $sections, true)) {
            $bundle['live_traffic'] = $trafficData;
        }
        if (in_array('content_studio', $sections, true)) {
            $bundle['content_studio'] = $contentData;
        }

        return $bundle;
    }

    /**
     * Build RFC-4180 UTF-8 CSV content + clean filename for any platform dataset.
     *
     * @return array{filename: string, csv: string, rows_count: int}
     */
    public function buildCsvExport(Website $website, string $dataset = 'full_summary', string $range = '28d'): array
    {
        $dataset = in_array($dataset, self::CSV_DATASETS, true) ? $dataset : 'full_summary';
        $range = $this->normalizeRange($range);
        $since = $this->resolveStartDate($range);

        $slugDomain = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $website->domain));
        $slugDomain = trim($slugDomain, '-') ?: 'website';
        $dateStamp = now()->format('Y-m-d');
        $filename = "{$slugDomain}-{$dataset}-{$range}-{$dateStamp}.csv";

        $rows = [];

        switch ($dataset) {
            case 'audit_issues':
                $audit = $this->collectSiteAudit($website);
                $rows[] = ['Severity', 'Category', 'Issue Type', 'Status', 'Affected URL', 'Explanation', 'Recommended Fix'];
                foreach ($audit['issues'] as $i) {
                    $rows[] = [
                        strtoupper($i['severity']),
                        $i['category'],
                        $i['title'],
                        $i['status'],
                        $i['affected_url'],
                        $i['explanation'],
                        $i['recommended_fix'],
                    ];
                }
                break;

            case 'crawled_pages':
                $audit = $this->collectSiteAudit($website);
                $rows[] = ['URL', 'HTTP Status', 'Load Time (ms)', 'Word Count', 'Page Title', 'Title Length', 'Meta Description Length', 'H1 Count', 'Indexable', 'In Sitemap', 'Images', 'Missing Alt'];
                foreach ($audit['crawled_pages'] as $p) {
                    $rows[] = [
                        $p['url'],
                        $p['status_code'],
                        $p['load_time_ms'],
                        $p['word_count'],
                        $p['page_title'],
                        $p['title_length'],
                        $p['meta_description_length'],
                        $p['h1_count'],
                        $p['is_indexable'] ? 'Yes' : 'No',
                        $p['in_sitemap'] ? 'Yes' : 'No',
                        $p['images_count'],
                        $p['images_missing_alt'],
                    ];
                }
                break;

            case 'action_plan':
                $plan = $this->collectActionPlan($website);
                $rows[] = ['Priority Score', 'Title', 'Category', 'Status', 'Impact', 'Confidence', 'Effort', 'Risk', 'Affected URL', 'Recommended Action', 'Expected Benefit'];
                foreach ($plan['items'] as $r) {
                    $rows[] = [
                        $r['priority_score'],
                        $r['title'],
                        $r['category'],
                        $r['status'],
                        $r['impact_score'],
                        $r['confidence_score'],
                        $r['effort_score'],
                        $r['risk_score'],
                        $r['affected_url'],
                        $r['recommended_action'],
                        $r['expected_benefit'],
                    ];
                }
                break;

            case 'gsc_queries':
                $gsc = $this->collectSearchConsole($website, $since);
                $rows[] = ['Rank #', 'Search Query', 'Clicks', 'Impressions', 'CTR (%)', 'Avg Position', 'Est. Google Page'];
                foreach ($gsc['queries'] as $idx => $q) {
                    $rows[] = [
                        $idx + 1,
                        $q['query'],
                        $q['clicks'],
                        $q['impressions'],
                        $q['ctr'],
                        $q['avg_position'],
                        $q['estimated_page'],
                    ];
                }
                break;

            case 'gsc_pages':
                $gsc = $this->collectSearchConsole($website, $since);
                $rows[] = ['Rank #', 'Landing Page URL', 'Clicks', 'Impressions', 'CTR (%)', 'Avg Position'];
                foreach ($gsc['pages'] as $idx => $p) {
                    $rows[] = [
                        $idx + 1,
                        $p['page'],
                        $p['clicks'],
                        $p['impressions'],
                        $p['ctr'],
                        $p['avg_position'],
                    ];
                }
                break;

            case 'search_opportunities':
                $gsc = $this->collectSearchConsole($website, $since);
                $rows[] = ['Type', 'Status', 'Impact Score', 'Target', 'Headline', 'Reasoning', 'Recommended Action'];
                foreach ($gsc['opportunities'] as $op) {
                    $rows[] = [
                        $op['type'],
                        $op['status'],
                        $op['impact_score'],
                        $op['target_identifier'],
                        $op['headline'],
                        $op['reasoning'],
                        $op['recommended_action'],
                    ];
                }
                break;

            case 'rank_checker':
                $ranks = $this->collectRankChecker($website);
                $rows[] = ['Keyword', 'Country', 'Location', 'Device', 'Language', 'Live Position', 'Google Page', 'Rank on Page', 'Ranking URL', 'GSC 28d Avg', 'Local Pack', 'SERP Features', 'Checked At'];
                foreach ($ranks['checks'] as $c) {
                    $rows[] = [
                        $c['keyword'],
                        $c['country_name'] . ' (' . $c['country'] . ')',
                        $c['location'],
                        $c['device'],
                        strtoupper($c['language']),
                        $c['target_position'] !== null ? '#' . $c['target_position'] : 'Not in Top ' . ($c['results_returned'] ?: 100),
                        $c['google_page'] !== null ? 'Page ' . $c['google_page'] : '-',
                        $c['rank_on_page'] !== null ? '#' . $c['rank_on_page'] : '-',
                        $c['ranking_url'] ?: '-',
                        $c['gsc_avg_position'] !== null ? '#' . $c['gsc_avg_position'] : '-',
                        $c['in_local_pack'] ? 'Yes' : 'No',
                        implode('; ', $c['serp_features']),
                        $c['captured_at'],
                    ];
                }
                break;

            case 'competitors':
                $comp = $this->collectCompetitors($website);
                $rows[] = ['Competitor Domain', 'Category', 'Tier', 'Country', 'On-Page SEO Score', 'Word Count', 'Load Time (ms)', 'Indexed / Sitemap Pages', 'Est. Organic Traffic', 'Referring Domains', 'Schema Types', 'Last Analyzed'];
                foreach ($comp['competitors'] as $c) {
                    $rows[] = [
                        $c['domain'],
                        $c['category'],
                        $c['tier'],
                        $c['country'],
                        $c['onpage_seo_score'] ?? '-',
                        $c['word_count'] ?? '-',
                        $c['load_time_ms'] ?? '-',
                        $c['indexed_pages'] ?? '-',
                        $c['organic_traffic_estimate'] ?? '-',
                        $c['referring_domains_count'] ?? '-',
                        implode('; ', $c['schema_types']),
                        $c['last_analyzed_at'] ?: '-',
                    ];
                }
                break;

            case 'keyword_gap':
                $comp = $this->collectCompetitors($website);
                $rows[] = ['Keyword', 'Gap Type', 'Country', 'Search Intent', 'Your Position', 'Competitor Positions', 'Search Volume', 'Difficulty', 'Opportunity Score', 'Data Source'];
                foreach ($comp['keyword_gaps'] as $k) {
                    $rows[] = [
                        $k['keyword'],
                        $k['gap_type'],
                        $k['country'],
                        $k['search_intent'] ?: '-',
                        $k['target_position'] ?? 'Not Ranked',
                        $k['competitor_positions_text'],
                        $k['search_volume'] ?? '-',
                        $k['keyword_difficulty'] ?? '-',
                        $k['opportunity_score'] ?? '-',
                        $k['data_source'] ?: '-',
                    ];
                }
                break;

            case 'content_gap':
                $comp = $this->collectCompetitors($website);
                $rows[] = ['Topic', 'Suggested Title', 'Primary Keyword', 'Content Type', 'Search Intent', 'Priority', 'Status', 'Rationale'];
                foreach ($comp['content_gaps'] as $g) {
                    $rows[] = [
                        $g['topic'],
                        $g['suggested_title'],
                        $g['primary_keyword'],
                        $g['suggested_content_type'],
                        $g['search_intent'],
                        $g['priority'],
                        $g['status'],
                        $g['rationale'],
                    ];
                }
                break;

            case 'live_traffic':
                $traffic = $this->collectTraffic($website, $range);
                $rows[] = ['Section', 'Dimension', 'Detail', 'Visitors', 'Pageviews', 'Share (%)'];
                foreach ($traffic['countries'] as $c) {
                    $rows[] = ['Countries', $c['country_name'], $c['country_code'], $c['visitors'], $c['pageviews'], $c['share_pct']];
                }
                foreach ($traffic['regions'] as $r) {
                    $rows[] = ['Regions / Cities', $r['region'], $r['city'] . ', ' . $r['country_name'], $r['visitors'], $r['pageviews'], ''];
                }
                foreach ($traffic['top_pages'] as $p) {
                    $rows[] = ['Top Pages', $p['path'], $p['title'], $p['visitors'], $p['pageviews'], ''];
                }
                foreach ($traffic['sources'] as $s) {
                    $rows[] = ['Traffic Sources', $s['source'], '', $s['visitors'], $s['pageviews'], $s['share_pct']];
                }
                break;

            case 'content_studio':
                $content = $this->collectContentStudio($website);
                $rows[] = ['Type', 'Title', 'Status', 'Word Count', 'Quality Score', 'Search Intent', 'Slug / Target Keyword', 'Updated At'];
                foreach ($content['articles'] as $a) {
                    $rows[] = ['Article', $a['title'], $a['status'], $a['word_count'], $a['quality_score'] ?? '-', $a['search_intent'] ?: '-', $a['slug'] ?: '-', $a['updated_at']];
                }
                foreach ($content['briefs'] as $b) {
                    $rows[] = ['Brief', $b['title'], $b['status'], $b['target_word_count'] ?? '-', '-', $b['search_intent'] ?: '-', $b['target_keyword'] ?: '-', $b['updated_at']];
                }
                break;

            case 'full_summary':
            default:
                $bundle = $this->buildBundle($website, $range, self::ALL_SECTIONS);
                $exec = $bundle['executive_summary'];
                $rows[] = ['Section', 'Metric / Item', 'Value / Position', 'Detail / URL', 'Extra / Recommendation'];

                // Executive Summary KPIs
                $rows[] = ['Executive Summary', 'Website', $website->domain, $website->target_url, 'Platform: ' . $website->platform];
                $rows[] = ['Executive Summary', 'Report Period', $bundle['range_label'], 'Generated: ' . $bundle['generated_at'], 'Report ID: ' . $bundle['report_id']];
                $rows[] = ['Executive Summary', 'Overall SEO Health Score', ($exec['overall_health_score'] ?? 0) . '/100', 'Technical: ' . ($exec['technical_score'] ?? '-') . ' | Content: ' . ($exec['content_score'] ?? '-'), 'Links: ' . ($exec['link_score'] ?? '-') . ' | Indexing: ' . ($exec['indexing_score'] ?? '-')];
                $rows[] = ['Executive Summary', 'Pages Crawled & Open Issues', $exec['pages_crawled'] . ' pages', $exec['open_issues_total'] . ' open issues', 'Critical: ' . $exec['critical_issues'] . ' | Warning: ' . $exec['warning_issues']];
                $rows[] = ['Executive Summary', 'Google Search Console', $exec['gsc_clicks'] . ' clicks / ' . $exec['gsc_impressions'] . ' impressions', 'CTR: ' . $exec['gsc_ctr'] . '%', 'Avg Position: #' . $exec['gsc_avg_position']];
                $rows[] = ['Executive Summary', 'Traffic (' . $exec['traffic_source'] . ')', $exec['traffic_visitors'] . ' visitors / ' . $exec['traffic_pageviews'] . ' pageviews', 'Active Now: ' . $exec['traffic_active_now'], 'Top Country: ' . ($exec['traffic_top_country'] ?: '-')];
                $rows[] = ['Executive Summary', 'Competitors & Gaps', $exec['competitors_count'] . ' competitors', $exec['keyword_gaps_count'] . ' keyword gaps', $exec['content_gaps_count'] . ' content gaps'];
                $rows[] = ['Executive Summary', 'Live Rank Checks', $exec['rank_checks_count'] . ' checks saved', $exec['rank_top10_count'] . ' in Top 10', ''];

                foreach (array_slice($bundle['site_audit']['issues'] ?? [], 0, 50) as $i) {
                    $rows[] = ['Site Audit Issue', '[' . strtoupper($i['severity']) . '] ' . $i['title'], $i['category'], $i['affected_url'], $i['recommended_fix']];
                }
                foreach (array_slice($bundle['action_plan']['items'] ?? [], 0, 30) as $a) {
                    $rows[] = ['SEO Action Plan', $a['title'], 'Priority ' . $a['priority_score'] . ' (' . $a['status'] . ')', $a['affected_url'], $a['recommended_action']];
                }
                foreach (array_slice($bundle['search_console']['queries'] ?? [], 0, 50) as $q) {
                    $rows[] = ['GSC Query', $q['query'], 'Pos #' . $q['avg_position'] . ' (Page ' . $q['estimated_page'] . ')', $q['clicks'] . ' clicks / ' . $q['impressions'] . ' impr', 'CTR ' . $q['ctr'] . '%'];
                }
                foreach (array_slice($bundle['rank_checker']['checks'] ?? [], 0, 40) as $rk) {
                    $posText = $rk['target_position'] !== null ? '#' . $rk['target_position'] . ' (Page ' . $rk['google_page'] . ')' : 'Not Found';
                    $rows[] = ['Live Rank Check', $rk['keyword'], $posText, $rk['location'] . ' (' . $rk['device'] . ')', $rk['ranking_url'] ?: '-'];
                }
                foreach ($bundle['competitors']['competitors'] ?? [] as $c) {
                    $rows[] = ['Competitor', $c['domain'], 'SEO Score: ' . ($c['onpage_seo_score'] ?? '-'), 'Category: ' . $c['category'] . ' (' . $c['country'] . ')', 'Words: ' . ($c['word_count'] ?? '-') . ' | Speed: ' . ($c['load_time_ms'] ?? '-') . 'ms'];
                }
                foreach (array_slice($bundle['live_traffic']['countries'] ?? [], 0, 25) as $tc) {
                    $rows[] = ['Traffic Country', $tc['country_name'], $tc['visitors'] . ' visitors', $tc['pageviews'] . ' pageviews', $tc['share_pct'] . '% share'];
                }
                break;
        }

        $csv = $this->encodeCsv($rows);

        return [
            'filename' => $filename,
            'csv' => $csv,
            'rows_count' => max(0, count($rows) - 1),
        ];
    }

    private function collectSiteAudit(Website $website): array
    {
        $latestAudit = AuditRun::where('website_id', $website->id)
            ->with('crawlJob')
            ->latest('id')
            ->first();

        $issues = collect();
        $crawledPages = collect();

        if ($latestAudit) {
            $issues = AuditIssue::where('audit_run_id', $latestAudit->id)
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->limit(200)
                ->get();

            if ($latestAudit->crawl_job_id) {
                $crawledPages = CrawledPage::where('crawl_job_id', $latestAudit->crawl_job_id)
                    ->orderBy('id')
                    ->limit(200)
                    ->get();
            }
        }

        if ($crawledPages->isEmpty()) {
            $crawledPages = CrawledPage::where('website_id', $website->id)
                ->latest('id')
                ->limit(100)
                ->get();
        }

        $openIssues = $issues->where('current_status', AuditIssue::STATUS_OPEN);

        return [
            'audit_run_id' => $latestAudit?->id,
            'audited_at' => $latestAudit?->created_at?->toIso8601String(),
            'overall_score' => $latestAudit?->overall_score ?? null,
            'technical_score' => $latestAudit?->technical_score ?? null,
            'content_score' => $latestAudit?->content_score ?? null,
            'link_score' => $latestAudit?->link_score ?? null,
            'indexing_score' => $latestAudit?->indexing_score ?? null,
            'pages_crawled' => $latestAudit?->crawlJob?->pages_crawled ?? $crawledPages->count(),
            'counts' => [
                'total_open' => $openIssues->count(),
                'critical' => $openIssues->where('severity', 'critical')->count(),
                'warning' => $openIssues->where('severity', 'warning')->count(),
                'info' => $openIssues->where('severity', 'info')->count(),
            ],
            'issues' => $issues->map(fn (AuditIssue $i) => [
                'id' => $i->id,
                'severity' => (string) $i->severity,
                'category' => (string) $i->category,
                'issue_type' => (string) $i->issue_type,
                'title' => ucwords(str_replace('_', ' ', (string) $i->issue_type)),
                'status' => (string) $i->current_status,
                'affected_url' => (string) ($i->affected_url ?? ''),
                'explanation' => (string) ($i->explanation ?? ''),
                'recommended_fix' => (string) ($i->recommended_fix ?? ''),
            ])->values()->toArray(),
            'crawled_pages' => $crawledPages->map(fn (CrawledPage $p) => [
                'url' => (string) $p->url,
                'status_code' => (int) ($p->status_code ?? 0),
                'load_time_ms' => (int) ($p->load_time_ms ?? 0),
                'word_count' => (int) ($p->word_count ?? 0),
                'page_title' => (string) ($p->page_title ?? ''),
                'title_length' => mb_strlen((string) ($p->page_title ?? '')),
                'meta_description_length' => mb_strlen((string) ($p->meta_description ?? '')),
                'h1_count' => is_array($p->h1_tags) ? count($p->h1_tags) : 0,
                'is_indexable' => (bool) $p->is_indexable,
                'in_sitemap' => (bool) $p->in_sitemap,
                'images_count' => (int) ($p->images_count ?? 0),
                'images_missing_alt' => (int) ($p->images_missing_alt ?? 0),
            ])->values()->toArray(),
        ];
    }

    private function collectActionPlan(Website $website): array
    {
        $recs = AiRecommendation::where('website_id', $website->id)
            ->orderByDesc('priority_score')
            ->limit(150)
            ->get();

        return [
            'counts' => [
                'total' => $recs->count(),
                'applied' => $recs->where('status', AiRecommendation::STATUS_APPLIED)->count(),
                'pending' => $recs->whereIn('status', [
                    AiRecommendation::STATUS_PROPOSED,
                    AiRecommendation::STATUS_ACCEPTED,
                    AiRecommendation::STATUS_IN_PROGRESS,
                ])->count(),
                'dismissed' => $recs->where('status', AiRecommendation::STATUS_DISMISSED)->count(),
            ],
            'items' => $recs->map(fn (AiRecommendation $r) => [
                'id' => $r->id,
                'title' => (string) $r->title,
                'category' => (string) $r->category,
                'status' => (string) $r->status,
                'priority_score' => (float) ($r->priority_score ?? 0),
                'impact_score' => (int) ($r->impact_score ?? 0),
                'confidence_score' => (int) ($r->confidence_score ?? 0),
                'effort_score' => (int) ($r->effort_score ?? 0),
                'risk_score' => (int) ($r->risk_score ?? 0),
                'affected_url' => (string) ($r->affected_url ?? ''),
                'recommended_action' => (string) ($r->recommended_action ?? ''),
                'expected_benefit' => (string) ($r->expected_benefit ?? ''),
            ])->values()->toArray(),
        ];
    }

    private function collectSearchConsole(Website $website, ?Carbon $since): array
    {
        // Prefer live exact GSC totals & breakdowns when live Google Search Console is connected
        $rangeKey = match (true) {
            $since === null => '90d',
            $since->diffInDays(now()) <= 8 => '7d',
            $since->diffInDays(now()) <= 35 => '28d',
            default => '90d',
        };

        $liveGsc = null;
        try {
            $liveGsc = app(\App\Domain\SearchConsole\GoogleSearchConsoleService::class)
                ->fetchLivePerformanceReport($website, $rangeKey);
        } catch (\Throwable) {
            $liveGsc = null;
        }

        if (is_array($liveGsc) && isset($liveGsc['summary'])) {
            $clicks = (int) ($liveGsc['summary']['clicks'] ?? 0);
            $impressions = (int) ($liveGsc['summary']['impressions'] ?? 0);
            $ctr = (float) ($liveGsc['summary']['ctr'] ?? 0.0);
            $avgPos = (float) ($liveGsc['summary']['position'] ?? 0.0);

            $queries = array_map(function ($row) {
                $pos = round((float) ($row['position'] ?? 0), 1);
                return [
                    'query' => (string) ($row['query'] ?? ''),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0.0),
                    'avg_position' => $pos,
                    'estimated_page' => max(1, (int) ceil($pos / 10)),
                ];
            }, $liveGsc['queries'] ?? []);

            $pages = array_map(function ($row) {
                return [
                    'page' => (string) ($row['page'] ?? ''),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => (float) ($row['ctr'] ?? 0.0),
                    'avg_position' => round((float) ($row['position'] ?? 0), 1),
                ];
            }, $liveGsc['pages'] ?? []);
        } else {
            $base = SearchPerformanceRecord::where('website_id', $website->id);
            if ($since) {
                $base->where('date', '>=', $since->toDateString());
            }

            $weightedPosSql = 'CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END';

            $totals = (clone $base)
                ->selectRaw("COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(impressions), 0) as impressions, {$weightedPosSql} as avg_pos")
                ->first();

            $clicks = (int) ($totals->clicks ?? 0);
            $impressions = (int) ($totals->impressions ?? 0);
            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0;
            $avgPos = $impressions > 0 ? round((float) ($totals->avg_pos ?? 0), 1) : 0.0;

            // Use exact cached GSC snapshot totals when available on integration metadata
            $integration = $website->gscIntegration;
            $snap = $integration?->metadata['exact_snapshots'][$rangeKey]
                ?? $integration?->metadata['exact_snapshots']['28d']
                ?? null;
            if (is_array($snap) && isset($snap['clicks'], $snap['impressions'])) {
                $clicks = (int) $snap['clicks'];
                $impressions = (int) $snap['impressions'];
                $ctr = (float) ($snap['ctr'] ?? $ctr);
                $avgPos = (float) ($snap['position'] ?? $avgPos);
            }

            $queries = (clone $base)
                ->whereNotNull('query')
                ->where('query', '!=', '')
                ->select('query')
                ->selectRaw("SUM(clicks) as clicks, SUM(impressions) as impressions, {$weightedPosSql} as avg_pos")
                ->groupBy('query')
                ->orderByDesc('clicks')
                ->orderByDesc('impressions')
                ->limit(150)
                ->get()
                ->map(function ($row) {
                    $c = (int) $row->clicks;
                    $i = (int) $row->impressions;
                    $pos = round((float) $row->avg_pos, 1);
                    return [
                        'query' => (string) $row->query,
                        'clicks' => $c,
                        'impressions' => $i,
                        'ctr' => $i > 0 ? round(($c / $i) * 100, 2) : 0.0,
                        'avg_position' => $pos,
                        'estimated_page' => max(1, (int) ceil($pos / 10)),
                    ];
                })->values()->toArray();

            $pages = (clone $base)
                ->whereNotNull('page')
                ->where('page', '!=', '')
                ->select('page')
                ->selectRaw("SUM(clicks) as clicks, SUM(impressions) as impressions, {$weightedPosSql} as avg_pos")
                ->groupBy('page')
                ->orderByDesc('clicks')
                ->orderByDesc('impressions')
                ->limit(100)
                ->get()
                ->map(function ($row) {
                    $c = (int) $row->clicks;
                    $i = (int) $row->impressions;
                    return [
                        'page' => (string) $row->page,
                        'clicks' => $c,
                        'impressions' => $i,
                        'ctr' => $i > 0 ? round(($c / $i) * 100, 2) : 0.0,
                        'avg_position' => round((float) $row->avg_pos, 1),
                    ];
                })->values()->toArray();
        }

        $opportunities = SearchOpportunity::where('website_id', $website->id)
            ->orderByDesc('impact_score')
            ->limit(100)
            ->get()
            ->map(fn (SearchOpportunity $o) => [
                'id' => $o->id,
                'type' => (string) $o->type,
                'status' => (string) $o->status,
                'impact_score' => (int) ($o->impact_score ?? 0),
                'target_identifier' => (string) ($o->target_identifier ?? ''),
                'headline' => (string) ($o->headline ?? ''),
                'reasoning' => (string) ($o->reasoning ?? ''),
                'recommended_action' => (string) ($o->recommended_action ?? ''),
            ])->values()->toArray();

        return [
            'summary' => [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $ctr,
                'avg_position' => $avgPos,
            ],
            'queries' => $queries,
            'pages' => $pages,
            'opportunities' => $opportunities,
        ];
    }

    private function collectRankChecker(Website $website): array
    {
        $snapshots = CompetitorSerpSnapshot::where('website_id', $website->id)
            ->orderByDesc('captured_at')
            ->limit(100)
            ->get();

        $checks = $snapshots->map(function (CompetitorSerpSnapshot $s) {
            $extras = is_array($s->content_patterns) ? $s->content_patterns : [];
            $organic = is_array($s->organic_results) ? $s->organic_results : [];
            $targetRow = collect($organic)->firstWhere('is_target', true);
            $localPack = is_array($extras['local_pack'] ?? null) ? $extras['local_pack'] : [];
            $inLocal = collect($localPack)->contains('is_target', true);
            $gscComp = is_array($extras['gsc_comparison'] ?? null) ? $extras['gsc_comparison'] : [];

            $pos = $s->target_position;
            $googlePage = $targetRow['google_page'] ?? ($pos ? (int) ceil($pos / 10) : null);
            $rankOnPage = $targetRow['rank_on_page'] ?? ($pos ? ((($pos - 1) % 10) + 1) : null);

            return [
                'id' => $s->id,
                'keyword' => (string) $s->keyword,
                'country' => (string) $s->country,
                'country_name' => CountryCatalog::name((string) $s->country),
                'location' => (string) ($s->location ?: CountryCatalog::name((string) $s->country)),
                'language' => (string) ($s->language ?: 'en'),
                'device' => (string) ($s->device ?: 'desktop'),
                'target_position' => $pos,
                'google_page' => $googlePage,
                'rank_on_page' => $rankOnPage,
                'ranking_url' => (string) ($targetRow['url'] ?? ''),
                'results_returned' => count($organic),
                'gsc_avg_position' => $gscComp['gsc_avg_position'] ?? null,
                'in_local_pack' => $inLocal,
                'serp_features' => is_array($s->serp_features) ? $s->serp_features : [],
                'captured_at' => $s->captured_at?->toIso8601String() ?? '',
            ];
        })->values();

        $found = $checks->filter(fn ($c) => $c['target_position'] !== null);

        return [
            'summary' => [
                'total_checks' => $checks->count(),
                'ranked_count' => $found->count(),
                'in_top_3' => $found->filter(fn ($c) => $c['target_position'] <= 3)->count(),
                'in_top_10' => $found->filter(fn ($c) => $c['target_position'] <= 10)->count(),
                'in_top_50' => $found->filter(fn ($c) => $c['target_position'] <= 50)->count(),
                'avg_position' => $found->isNotEmpty() ? round($found->avg('target_position'), 1) : null,
            ],
            'checks' => $checks->toArray(),
        ];
    }

    private function collectCompetitors(Website $website): array
    {
        $competitors = CompetitorDomain::where('website_id', $website->id)
            ->where('is_active', true)
            ->with('latestSnapshot')
            ->orderBy('tier')
            ->orderBy('domain')
            ->get()
            ->map(function (CompetitorDomain $c) {
                $snap = $c->latestSnapshot;
                $sig = is_array($snap?->crawled_signals) ? $snap->crawled_signals : [];
                return [
                    'id' => $c->id,
                    'domain' => (string) $c->domain,
                    'category' => (string) $c->category,
                    'tier' => (string) $c->tier,
                    'country' => (string) ($c->country ?: 'US'),
                    'onpage_seo_score' => $sig['onpage_seo_score'] ?? null,
                    'word_count' => $sig['word_count'] ?? null,
                    'load_time_ms' => $sig['load_time_ms'] ?? null,
                    'indexed_pages' => $snap?->indexed_pages_count ?? ($sig['sitemap_urls_count'] ?? null),
                    'organic_traffic_estimate' => $snap?->organic_traffic_estimate,
                    'referring_domains_count' => $snap?->referring_domains_count,
                    'schema_types' => is_array($sig['structured_data_types'] ?? null) ? $sig['structured_data_types'] : [],
                    'last_analyzed_at' => $c->last_analyzed_at?->toIso8601String() ?? $snap?->captured_at?->toIso8601String(),
                ];
            })->values()->toArray();

        $keywordGaps = CompetitorKeyword::where('website_id', $website->id)
            ->orderByDesc('opportunity_score')
            ->limit(150)
            ->get()
            ->map(function (CompetitorKeyword $k) {
                $positions = is_array($k->competitor_positions) ? $k->competitor_positions : [];
                $posParts = [];
                foreach ($positions as $dom => $pos) {
                    $posParts[] = "{$dom}: #{$pos}";
                }
                return [
                    'keyword' => (string) $k->keyword,
                    'gap_type' => (string) $k->gap_type,
                    'country' => (string) ($k->country ?: 'US'),
                    'search_intent' => (string) ($k->search_intent ?? ''),
                    'target_position' => $k->target_position,
                    'competitor_positions_text' => implode(', ', $posParts),
                    'search_volume' => $k->search_volume,
                    'keyword_difficulty' => $k->keyword_difficulty,
                    'opportunity_score' => $k->opportunity_score,
                    'data_source' => (string) ($k->data_source ?? ''),
                ];
            })->values()->toArray();

        $contentGaps = CompetitorContentGap::where('website_id', $website->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (CompetitorContentGap $g) => [
                'topic' => (string) $g->topic,
                'suggested_title' => (string) ($g->suggested_title ?? ''),
                'primary_keyword' => (string) ($g->primary_keyword ?? ''),
                'suggested_content_type' => (string) ($g->suggested_content_type ?? ''),
                'search_intent' => (string) ($g->search_intent ?? ''),
                'priority' => (string) ($g->priority ?? 'medium'),
                'status' => (string) ($g->status ?? 'open'),
                'rationale' => (string) ($g->rationale ?? ''),
            ])->values()->toArray();

        return [
            'competitors' => $competitors,
            'keyword_gaps' => $keywordGaps,
            'content_gaps' => $contentGaps,
        ];
    }

    private function collectTraffic(Website $website, string $range): array
    {
        $trafficRange = match ($range) {
            '7d' => '7d',
            '28d', '30d', '90d', 'all' => '30d',
            default => '30d',
        };

        $payload = null;
        $sourceLabel = '1st-Party Live Tracker';

        // Prefer live GA4 if connected and returning data, otherwise 1st-party live traffic
        try {
            $ga4Integration = WebsiteIntegration::where('website_id', $website->id)
                ->where('provider', WebsiteIntegration::PROVIDER_GA4)
                ->where('status', WebsiteIntegration::STATUS_CONNECTED)
                ->first();

            if ($ga4Integration) {
                $ga4 = $this->ga4Service->getRealtimeDashboard($website, $ga4Integration, $trafficRange);
                if (!empty($ga4['available'])) {
                    $payload = $ga4;
                    $sourceLabel = 'Google Analytics 4';
                }
            }
        } catch (Throwable) {
            // Gracefully fall back to 1st-party live traffic store
        }

        if ($payload === null) {
            $payload = $this->liveTrafficService->getRealtimeStats($website, $trafficRange);
        }

        $countries = array_map(fn ($c) => [
            'country_name' => (string) ($c['country_name'] ?? 'Unknown'),
            'country_code' => (string) ($c['country_code'] ?? ''),
            'visitors' => (int) ($c['visitors'] ?? 0),
            'pageviews' => (int) ($c['pageviews'] ?? 0),
            'share_pct' => (float) ($c['share_pct'] ?? 0),
        ], $payload['by_country'] ?? []);

        $regions = array_map(fn ($r) => [
            'region' => (string) ($r['region'] ?? 'Unknown'),
            'city' => (string) ($r['city'] ?? ''),
            'country_name' => (string) ($r['country_name'] ?? ''),
            'visitors' => (int) ($r['visitors'] ?? 0),
            'pageviews' => (int) ($r['pageviews'] ?? 0),
        ], $payload['by_region'] ?? []);

        $topPages = array_map(fn ($p) => [
            'path' => (string) ($p['path'] ?? '/'),
            'title' => (string) ($p['page_title'] ?? $p['title'] ?? ''),
            'visitors' => (int) ($p['visitors'] ?? 0),
            'pageviews' => (int) ($p['pageviews'] ?? 0),
        ], $payload['top_pages'] ?? []);

        $sources = array_map(fn ($s) => [
            'source' => (string) ($s['source'] ?? $s['referrer'] ?? 'Direct'),
            'visitors' => (int) ($s['visitors'] ?? 0),
            'pageviews' => (int) ($s['pageviews'] ?? 0),
            'share_pct' => (float) ($s['share_pct'] ?? 0),
        ], $payload['top_referrers'] ?? []);

        $topCountryObj = $payload['summary']['top_country'] ?? ($countries[0] ?? null);
        $topCountryName = is_array($topCountryObj)
            ? ($topCountryObj['country_name'] ?? null)
            : (is_string($topCountryObj) ? $topCountryObj : null);

        return [
            'source' => $sourceLabel,
            'summary' => [
                'active_visitors_now' => (int) ($payload['summary']['active_visitors_now'] ?? 0),
                'unique_visitors' => (int) ($payload['summary']['unique_visitors_range'] ?? $payload['summary']['unique_visitors'] ?? 0),
                'pageviews' => (int) ($payload['summary']['pageviews_range'] ?? $payload['summary']['pageviews'] ?? 0),
                'top_country' => $topCountryName,
            ],
            'countries' => $countries,
            'regions' => $regions,
            'top_pages' => $topPages,
            'sources' => $sources,
            'devices' => $payload['devices'] ?? [],
        ];
    }

    private function collectContentStudio(Website $website): array
    {
        $articles = ContentArticle::where('website_id', $website->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (ContentArticle $a) => [
                'id' => $a->id,
                'title' => (string) $a->title,
                'slug' => (string) ($a->slug ?? ''),
                'status' => (string) $a->status,
                'word_count' => (int) ($a->word_count ?? 0),
                'quality_score' => $a->quality_score,
                'search_intent' => (string) ($a->search_intent ?? ''),
                'version' => (int) ($a->version ?? 1),
                'updated_at' => $a->updated_at?->toIso8601String() ?? '',
            ])->values()->toArray();

        $briefs = ContentBrief::where('website_id', $website->id)
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (ContentBrief $b) => [
                'id' => $b->id,
                'title' => (string) ($b->suggested_title ?? $b->target_keyword ?? ''),
                'target_keyword' => (string) ($b->target_keyword ?? ''),
                'status' => (string) ($b->status ?? 'draft'),
                'target_word_count' => $b->word_count_target ?? null,
                'search_intent' => (string) ($b->search_intent ?? ''),
                'updated_at' => $b->updated_at?->toIso8601String() ?? '',
            ])->values()->toArray();

        return [
            'articles' => $articles,
            'briefs' => $briefs,
        ];
    }

    private function normalizeRange(string $range): string
    {
        $clean = strtolower(trim($range));
        return in_array($clean, ['7d', '28d', '30d', '90d', 'all'], true) ? $clean : '28d';
    }

    private function resolveStartDate(string $range): ?Carbon
    {
        return match ($range) {
            '7d' => now()->subDays(7),
            '28d' => now()->subDays(28),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
    }

    private function rangeLabel(string $range): string
    {
        return match ($range) {
            '7d' => 'Last 7 Days',
            '28d' => 'Last 28 Days',
            '30d' => 'Last 30 Days',
            '90d' => 'Last 90 Days',
            default => 'All Time',
        };
    }

    private function normalizeSections(?array $requested): array
    {
        if (empty($requested) || in_array('all', $requested, true)) {
            return self::ALL_SECTIONS;
        }
        $filtered = array_values(array_intersect(self::ALL_SECTIONS, array_map('trim', $requested)));
        return !empty($filtered) ? $filtered : self::ALL_SECTIONS;
    }

    /**
     * Encode rows into UTF-8 BOM RFC-4180 CSV with formula-injection protection.
     */
    private function encodeCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');
        // UTF-8 BOM for clean Excel / Google Sheets import of international characters
        fwrite($stream, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            $safeRow = array_map(function ($cell) {
                if ($cell === null) {
                    return '';
                }
                if (is_bool($cell)) {
                    return $cell ? 'Yes' : 'No';
                }
                if (is_int($cell) || is_float($cell)) {
                    return (string) $cell;
                }
                $str = str_replace(["\r\n", "\r", "\n"], ' ', trim((string) $cell));
                // Prevent Excel CSV formula injection while keeping numeric/position strings clean
                if ($str !== '' && preg_match('/^[=+\-@\t]/', $str) && !is_numeric($str)) {
                    $str = "'" . $str;
                }
                return $str;
            }, $row);

            fputcsv($stream, $safeRow);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? '' : $content;
    }
}
