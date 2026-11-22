<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Domain\Competitors\Providers\FirstPartyCrawlProvider;
use App\Models\AiRecommendation;
use App\Models\CompetitorDomain;
use App\Models\CompetitorDomainSnapshot;
use App\Models\CompetitorKeyword;
use App\Models\CrawledPage;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * Generates an executive-grade Head-to-Head Competitor Deep Dive & Improvement Roadmap
 * comparing the target website against a specific tracked competitor using 100% real,
 * verified First-Party Crawl + Google Search (Serper.dev) + Third-Party metrics (when configured).
 */
class CompetitorDeepDiveService
{
    public function __construct(
        private CompetitorProviderManager $providerManager,
        private DomainComparisonService $comparisonService
    ) {
    }

    public function buildDeepDive(
        Website $website,
        CompetitorDomain $competitor,
        bool $forceRefresh = false
    ): array {
        $country = $competitor->country ?: ($website->target_country ?: 'US');
        $language = $competitor->language ?: ($website->preferred_language ?: 'en');

        // 1. Ensure both Target and Competitor have snapshots (auto-scan if missing or refresh requested)
        $targetSnap = CompetitorDomainSnapshot::where('website_id', $website->id)
            ->where('is_target', true)
            ->orderByDesc('captured_at')
            ->first();

        if (!$targetSnap || $forceRefresh || !isset($targetSnap->crawled_signals['onpage_seo_score'])) {
            $targetSnap = $this->comparisonService->captureDomainSnapshot(
                $website,
                $website->domain,
                true,
                null,
                $country,
                $language,
                $forceRefresh
            );
        }

        $compSnap = CompetitorDomainSnapshot::where('competitor_domain_id', $competitor->id)
            ->orderByDesc('captured_at')
            ->first();

        if (!$compSnap || $forceRefresh || !isset($compSnap->crawled_signals['onpage_seo_score'])) {
            $compSnap = $this->comparisonService->captureDomainSnapshot(
                $website,
                $competitor->domain,
                false,
                $competitor,
                $country,
                $language,
                $forceRefresh
            );
        }

        $tSig = is_array($targetSnap->crawled_signals) ? $targetSnap->crawled_signals : [];
        $cSig = is_array($compSnap->crawled_signals) ? $compSnap->crawled_signals : [];

        // 2. Run targeted head-to-head Google keyword battles via Serper/SERP provider if none exist for this competitor yet
        $keywordBattles = $this->collectHeadToHeadKeywords($website, $competitor, $targetSnap, $compSnap, $country, $language, $forceRefresh);

        // 3. Build 12-Metric Head-to-Head Scorecard
        $scorecard = $this->buildScorecard($website, $competitor, $targetSnap, $compSnap, $tSig, $cSig);

        $wins = count(array_filter($scorecard, fn ($r) => $r['winner'] === 'target'));
        $losses = count(array_filter($scorecard, fn ($r) => $r['winner'] === 'competitor'));
        $ties = count(array_filter($scorecard, fn ($r) => $r['winner'] === 'tie'));

        // 4. Analyze Competitor Top Pages vs Target Crawled Pages to flag Missing Topic Pages
        $targetPages = CrawledPage::where('website_id', $website->id)->get(['url', 'page_title', 'h1_tags']);
        $targetCorpus = strtolower($targetPages->map(fn ($p) => ($p->url ?? '') . ' ' . ($p->page_title ?? '') . ' ' . implode(' ', is_array($p->h1_tags) ? $p->h1_tags : []))->implode(' | '));
        $targetCorpus .= ' ' . strtolower(implode(' ', array_map(fn ($p) => ($p['url'] ?? '') . ' ' . ($p['title'] ?? ''), $targetSnap->top_pages ?? [])));

        $competitorPages = [];
        $missingPages = [];
        foreach (array_slice($compSnap->top_pages ?? [], 0, 12) as $page) {
            $url = (string) ($page['url'] ?? '');
            $title = trim((string) ($page['title'] ?? ''));
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $isUtility = $path === '' || (bool) preg_match('/^(privacy|terms|contact|login|signin|signup|cart|checkout|wp-|feed)/i', $path);
            $covered = $isUtility || $this->isTopicCovered($title !== '' ? $title : str_replace(['-', '_'], ' ', basename($path)), $targetCorpus);

            $row = [
                'url' => $url,
                'title' => $title ?: ($path ?: 'Homepage'),
                'snippet' => $page['snippet'] ?? null,
                'source' => $page['source'] ?? $compSnap->data_source,
                'missing_on_target' => !$covered && !$isUtility,
            ];
            $competitorPages[] = $row;
            if ($row['missing_on_target']) {
                $missingPages[] = $row;
            }
        }

        // 5. Find Web Mention / Citation Opportunities (domains mentioning competitor on Google but not target)
        $targetMentionDomains = array_map(fn ($m) => strtolower((string) ($m['domain'] ?? '')), $tSig['web_mentions'] ?? []);
        $citationOpportunities = [];
        foreach ($cSig['web_mentions'] ?? [] as $m) {
            $mDomain = strtolower((string) ($m['domain'] ?? ''));
            if ($mDomain !== '' && !in_array($mDomain, $targetMentionDomains, true)) {
                $citationOpportunities[] = $m;
            }
        }

        // 6. Generate Concrete, Prioritized Improvement Plan ("How to Outrank {competitor.domain}")
        $improvements = $this->buildImprovementPlan(
            $website,
            $competitor,
            $targetSnap,
            $compSnap,
            $tSig,
            $cSig,
            $missingPages,
            $keywordBattles,
            $citationOpportunities
        );

        $targetSeoScore = $tSig['onpage_seo_score'] ?? FirstPartyCrawlProvider::computeOnPageSeoScore($tSig);
        $compSeoScore = $cSig['onpage_seo_score'] ?? FirstPartyCrawlProvider::computeOnPageSeoScore($cSig);

        $verdict = match (true) {
            $losses === 0 && $wins > 0 => "{$website->domain} currently leads or matches {$competitor->domain} across all verified signals. Focus on publishing missing topic pages and expanding search visibility.",
            $losses > $wins => "{$competitor->domain} currently holds an edge in {$losses} verified SEO areas. Completing the {$losses} priority improvements below will close the gap directly.",
            default => "{$website->domain} and {$competitor->domain} are closely matched ({$wins} wins vs {$losses} gaps). Focus on the highest-impact technical, schema, and content wins below to pull ahead.",
        };

        return [
            'target' => [
                'domain' => CompetitorDomain::normalizeDomain($website->domain),
                'name' => $website->name,
                'seo_score' => $targetSeoScore,
                'snapshot' => $targetSnap,
            ],
            'competitor' => [
                'id' => $competitor->id,
                'domain' => $competitor->domain,
                'display_name' => $competitor->display_name ?: $competitor->domain,
                'category' => $competitor->category,
                'tier' => $competitor->tier,
                'country' => $country,
                'language' => $language,
                'discovery_confidence' => $competitor->discovery_confidence,
                'discovery_rationale' => $competitor->discovery_rationale,
                'seo_score' => $compSeoScore,
                'snapshot' => $compSnap,
            ],
            'summary' => [
                'wins' => $wins,
                'ties' => $ties,
                'gaps' => $losses,
                'improvements_count' => count($improvements),
                'missing_pages_count' => count($missingPages),
                'citation_opportunities_count' => count($citationOpportunities),
                'verdict' => $verdict,
                'last_analyzed_at' => $compSnap->captured_at?->toIso8601String(),
            ],
            'scorecard' => $scorecard,
            'improvements' => $improvements,
            'competitor_profile' => [
                'page_title' => $cSig['page_title'] ?? null,
                'meta_description' => $cSig['meta_description'] ?? null,
                'h1_tags' => $cSig['h1_tags'] ?? [],
                'h2_outline' => $cSig['h2_tags'] ?? [],
                'structured_data_types' => $cSig['structured_data_types'] ?? [],
                'has_sitemap' => (bool) ($cSig['has_sitemap'] ?? false),
                'has_blog_section' => (bool) ($cSig['has_blog_section'] ?? false),
                'has_faq_signal' => (bool) ($cSig['has_faq_signal'] ?? false),
                'has_contact_cta' => (bool) ($cSig['has_contact_cta'] ?? false),
                'is_blocked_by_waf' => (bool) ($cSig['is_blocked_by_waf'] ?? false),
                'top_pages' => $competitorPages,
                'web_mentions' => $cSig['web_mentions'] ?? [],
            ],
            'citation_opportunities' => array_slice($citationOpportunities, 0, 10),
            'keyword_battles' => $keywordBattles,
        ];
    }

    /**
     * Persist a specific Deep Dive improvement item straight into the website's SEO Action Plan.
     */
    public function saveImprovementToActionPlan(Website $website, CompetitorDomain $competitor, array $imp): AiRecommendation
    {
        $priority = strtolower((string) ($imp['priority'] ?? 'high'));
        $effort = strtolower((string) ($imp['effort'] ?? 'medium'));
        $impactScore = match ($priority) {
            'critical' => 9,
            'high' => 8,
            'medium' => 6,
            default => 5,
        };
        $effortScore = match ($effort) {
            'low' => 2,
            'high' => 5,
            default => 3,
        };

        $steps = is_array($imp['action_steps'] ?? null) ? implode(' ', array_map(fn ($s, $i) => ($i + 1) . '. ' . $s, $imp['action_steps'], array_keys($imp['action_steps']))) : '';

        return AiRecommendation::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'title' => Str::limit((string) ($imp['title'] ?? "Outrank {$competitor->domain}"), 200, ''),
            'category' => in_array($imp['category'] ?? '', ['technical', 'content', 'schema', 'metadata', 'links'], true) ? $imp['category'] : 'content',
            'affected_url' => $imp['affected_url'] ?? $website->target_url,
            'evidence' => [
                'source' => 'competitor_deep_dive',
                'competitor_domain' => $competitor->domain,
                'your_current' => $imp['your_current'] ?? null,
                'competitor_benchmark' => $imp['competitor_benchmark'] ?? null,
                'why_it_matters' => $imp['why_it_matters'] ?? null,
            ],
            'recommended_action' => trim($steps ?: ($imp['why_it_matters'] ?? 'Implement this competitive improvement.')),
            'reasoning_summary' => "Head-to-head gap vs {$competitor->domain}: Your site ({$imp['your_current']}) vs Competitor ({$imp['competitor_benchmark']}).",
            'expected_benefit' => (string) ($imp['expected_impact'] ?? 'Closes verified competitive SEO gap against tracked rival.'),
            'confidence_level' => 'high',
            'effort' => $effort,
            'risk_level' => 'low',
            'required_permissions' => 'editor',
            'verification_procedure' => "Re-run Competitor Deep Dive against {$competitor->domain} after applying the update to confirm your metric surpasses the competitor benchmark.",
            'impact_score' => $impactScore,
            'confidence_score' => 8,
            'effort_score' => $effortScore,
            'risk_score' => 1,
            'priority_score' => AiRecommendation::calculatePriority($impactScore, 8, $effortScore, 1),
            'status' => AiRecommendation::STATUS_PROPOSED,
        ]);
    }

    private function buildScorecard(
        Website $website,
        CompetitorDomain $competitor,
        CompetitorDomainSnapshot $tSnap,
        CompetitorDomainSnapshot $cSnap,
        array $tSig,
        array $cSig
    ): array {
        $tScore = $tSig['onpage_seo_score'] ?? null;
        $cScore = $cSig['onpage_seo_score'] ?? null;

        $tWords = $tSig['word_count'] ?? null;
        $cWords = $cSig['word_count'] ?? null;

        $tSpeed = $tSig['load_time_ms'] ?? null;
        $cSpeed = $cSig['load_time_ms'] ?? null;

        $tIndexed = $tSnap->indexed_pages_count ?? ($tSig['google_indexed_sample_count'] ?? null);
        $cIndexed = $cSnap->indexed_pages_count ?? ($cSig['google_indexed_sample_count'] ?? null);

        $tSchema = count($tSig['structured_data_types'] ?? []);
        $cSchema = count($cSig['structured_data_types'] ?? []);

        $tH2 = isset($tSig['h2_count']) ? (int) $tSig['h2_count'] : null;
        $cH2 = isset($cSig['h2_count']) ? (int) $cSig['h2_count'] : null;

        $tTitleLen = isset($tSig['title_length']) ? (int) $tSig['title_length'] : (isset($tSig['page_title']) ? mb_strlen((string) $tSig['page_title']) : null);
        $cTitleLen = isset($cSig['title_length']) ? (int) $cSig['title_length'] : (isset($cSig['page_title']) ? mb_strlen((string) $cSig['page_title']) : null);

        $tMetaLen = isset($tSig['meta_description_length']) ? (int) $tSig['meta_description_length'] : (isset($tSig['meta_description']) ? mb_strlen((string) $tSig['meta_description']) : null);
        $cMetaLen = isset($cSig['meta_description_length']) ? (int) $cSig['meta_description_length'] : (isset($cSig['meta_description']) ? mb_strlen((string) $cSig['meta_description']) : null);

        $tAlt = $tSig['image_alt_coverage_pct'] ?? null;
        $cAlt = $cSig['image_alt_coverage_pct'] ?? null;

        $tLinks = $tSig['internal_links_count'] ?? null;
        $cLinks = $cSig['internal_links_count'] ?? null;

        $tMentions = $tSig['web_mentions_count'] ?? null;
        $cMentions = $cSig['web_mentions_count'] ?? null;

        $rows = [
            $this->metricRow('On-Page SEO Score', 'Overall', $tScore, $cScore, $tScore !== null ? "{$tScore}/100" : 'Pending crawl', $cScore !== null ? "{$cScore}/100" : 'WAF/Unavailable', 'higher', 'Calculated First-Party'),
            $this->metricRow('Homepage Content Depth', 'Content', $tWords, $cWords, $tWords !== null ? number_format($tWords) . ' words' : 'N/A', $cWords !== null ? number_format($cWords) . ' words' : 'N/A', 'higher', 'Verified First-Party'),
            $this->metricRow('Server Response Speed', 'Technical', $tSpeed, $cSpeed, $tSpeed !== null ? "{$tSpeed} ms" : 'N/A', $cSpeed !== null ? "{$cSpeed} ms" : 'N/A', 'lower', 'Verified First-Party'),
            $this->metricRow('Indexed / Discovered Pages', 'Visibility', $tIndexed, $cIndexed, $tIndexed !== null ? number_format($tIndexed) . ' pages' : 'N/A', $cIndexed !== null ? number_format($cIndexed) . ' pages' : 'N/A', 'higher', isset($cSig['google_indexed_sample_count']) ? 'Google Index / Sitemap' : 'Verified Sitemap/Crawl'),
            $this->metricRow('Schema.org Markup Types', 'Technical', $tSchema, $cSchema, $tSchema > 0 ? "{$tSchema} (" . implode(', ', array_slice($tSig['structured_data_types'] ?? [], 0, 3)) . ')' : '0 types', $cSchema > 0 ? "{$cSchema} (" . implode(', ', array_slice($cSig['structured_data_types'] ?? [], 0, 3)) . ')' : '0 types', 'higher', 'Verified First-Party'),
            $this->metricRow('Heading Structure (H2 Sections)', 'On-Page SEO', $tH2, $cH2, $tH2 !== null ? "{$tH2} H2 tags" : 'N/A', $cH2 !== null ? "{$cH2} H2 tags" : 'N/A', 'higher', 'Verified First-Party'),
            $this->metricRow('Title Tag Length (Ideal 30–65)', 'On-Page SEO', $tTitleLen, $cTitleLen, $tTitleLen ? "{$tTitleLen} chars" : 'Missing', $cTitleLen ? "{$cTitleLen} chars" : 'Missing', 'range_title', 'Verified First-Party'),
            $this->metricRow('Meta Description (Ideal 70–160)', 'On-Page SEO', $tMetaLen, $cMetaLen, $tMetaLen ? "{$tMetaLen} chars" : 'Missing', $cMetaLen ? "{$cMetaLen} chars" : 'Missing', 'range_meta', 'Verified First-Party'),
            $this->metricRow('Image Alt-Text Coverage', 'Accessibility', $tAlt, $cAlt, $tAlt !== null ? "{$tAlt}%" : 'N/A', $cAlt !== null ? "{$cAlt}%" : 'N/A', 'higher', 'Verified First-Party'),
            $this->metricRow('Internal Links on Homepage', 'Architecture', $tLinks, $cLinks, $tLinks !== null ? "{$tLinks} links" : 'N/A', $cLinks !== null ? "{$cLinks} links" : 'N/A', 'higher', 'Verified First-Party'),
            $this->metricRow('Public Google Web Mentions', 'Authority', $tMentions, $cMentions, $tMentions !== null ? "{$tMentions} mentions" : 'Connect Serper', $cMentions !== null ? "{$cMentions} mentions" : 'Connect Serper', 'higher', 'Serper.dev Google'),
        ];

        // Append third-party metrics ONLY when at least one side has data (so we never spam empty rows)
        if ($tSnap->organic_traffic_estimate !== null || $cSnap->organic_traffic_estimate !== null) {
            $rows[] = $this->metricRow('Est. Monthly Organic Traffic', '3rd-Party', $tSnap->organic_traffic_estimate, $cSnap->organic_traffic_estimate, $tSnap->organic_traffic_estimate !== null ? number_format($tSnap->organic_traffic_estimate) : 'N/A', $cSnap->organic_traffic_estimate !== null ? number_format($cSnap->organic_traffic_estimate) : 'N/A', 'higher', 'Third-Party Estimate');
        }
        if ($tSnap->referring_domains_count !== null || $cSnap->referring_domains_count !== null) {
            $rows[] = $this->metricRow('Referring Domains', '3rd-Party', $tSnap->referring_domains_count, $cSnap->referring_domains_count, $tSnap->referring_domains_count !== null ? number_format($tSnap->referring_domains_count) : 'N/A', $cSnap->referring_domains_count !== null ? number_format($cSnap->referring_domains_count) : 'N/A', 'higher', 'Third-Party Estimate');
        }

        return $rows;
    }

    private function metricRow(
        string $metric,
        string $category,
        mixed $tVal,
        mixed $cVal,
        string $tDisp,
        string $cDisp,
        string $rule,
        string $source
    ): array {
        $winner = 'unavailable';

        if ($tVal !== null && $cVal !== null) {
            if ($rule === 'higher') {
                $winner = $tVal > $cVal ? 'target' : ($cVal > $tVal ? 'competitor' : 'tie');
            } elseif ($rule === 'lower') {
                if ($tVal > 0 && $cVal > 0) {
                    $winner = $tVal < $cVal ? 'target' : ($cVal < $tVal ? 'competitor' : 'tie');
                }
            } elseif ($rule === 'range_title') {
                $tOk = $tVal >= 25 && $tVal <= 68;
                $cOk = $cVal >= 25 && $cVal <= 68;
                $winner = $tOk && !$cOk ? 'target' : ($cOk && !$tOk ? 'competitor' : ($tOk && $cOk ? 'tie' : 'tie'));
            } elseif ($rule === 'range_meta') {
                $tOk = $tVal >= 70 && $tVal <= 170;
                $cOk = $cVal >= 70 && $cVal <= 170;
                $winner = $tOk && !$cOk ? 'target' : ($cOk && !$tOk ? 'competitor' : 'tie');
            }
        }

        return [
            'metric' => $metric,
            'category' => $category,
            'target_value' => $tVal,
            'competitor_value' => $cVal,
            'target_display' => $tDisp,
            'competitor_display' => $cDisp,
            'winner' => $winner,
            'source_label' => $source,
        ];
    }

    private function buildImprovementPlan(
        Website $website,
        CompetitorDomain $competitor,
        CompetitorDomainSnapshot $tSnap,
        CompetitorDomainSnapshot $cSnap,
        array $tSig,
        array $cSig,
        array $missingPages,
        array $keywordBattles,
        array $citationOpportunities
    ): array {
        $items = [];
        $compDomain = $competitor->domain;

        // 1. Content Depth Gap
        $tWords = $tSig['word_count'] ?? null;
        $cWords = $cSig['word_count'] ?? null;
        if ($tWords !== null && $cWords !== null && $cWords > $tWords + 120) {
            $diff = $cWords - $tWords;
            $items[] = [
                'id' => 'content-depth',
                'priority' => $diff >= 400 ? 'critical' : 'high',
                'category' => 'content',
                'title' => "Expand Homepage Content Depth (+{$diff} words to surpass {$compDomain})",
                'your_current' => "{$tWords} words on homepage",
                'competitor_benchmark' => "{$cWords} words on {$compDomain}",
                'why_it_matters' => "Google evaluates topical depth and helpfulness. {$compDomain} publishes {$diff} more words covering buyer questions, service details, and trust proof.",
                'action_steps' => [
                    'Add clear service breakdown sections with H2 headings matching local search intent.',
                    'Include a dedicated FAQ section answering pricing, safety, verification, and booking questions.',
                    'Add verified customer testimonials, service coverage areas, and step-by-step booking details.',
                ],
                'expected_impact' => 'Improves topical authority, long-tail keyword coverage, and conversion trust.',
                'effort' => 'medium',
                'affected_url' => $website->target_url,
            ];
        }

        // 2. Schema.org Structured Data Gap
        $tSchema = $tSig['structured_data_types'] ?? [];
        $cSchema = $cSig['structured_data_types'] ?? [];
        $missingSchemas = array_values(array_diff($cSchema, $tSchema));
        if (empty($tSchema) || !empty($missingSchemas)) {
            $recommendedSchemas = !empty($missingSchemas) ? $missingSchemas : ['LocalBusiness', 'Organization', 'FAQPage', 'Service'];
            $items[] = [
                'id' => 'schema-markup',
                'priority' => empty($tSchema) ? 'high' : 'medium',
                'category' => 'schema',
                'title' => 'Add Missing JSON-LD Structured Data (' . implode(', ', array_slice($recommendedSchemas, 0, 3)) . ')',
                'your_current' => empty($tSchema) ? '0 JSON-LD schema types detected' : implode(', ', $tSchema),
                'competitor_benchmark' => empty($cSchema) ? 'Opportunity to leapfrog rival with rich snippets' : implode(', ', $cSchema),
                'why_it_matters' => 'Structured data enables Google rich results (FAQ dropdowns, business ratings, local service info) which lift click-through rate even before rank #1.',
                'action_steps' => [
                    'Add valid JSON-LD markup for ' . implode(', ', array_slice($recommendedSchemas, 0, 3)) . ' in your site <head>.',
                    'Include NAP details (Business Name, Local Address, Phone, Service Area, Opening Hours).',
                    'Validate with Google Rich Results Test after deploying.',
                ],
                'expected_impact' => 'Unlocks rich SERP features and stronger local entity recognition in Google.',
                'effort' => 'low',
                'affected_url' => $website->target_url,
            ];
        }

        // 3. Missing Competitor Pages / Content Gap
        if (!empty($missingPages)) {
            $sampleTitles = array_slice(array_map(fn ($p) => $p['title'], $missingPages), 0, 4);
            $items[] = [
                'id' => 'missing-pages',
                'priority' => count($missingPages) >= 3 ? 'critical' : 'high',
                'category' => 'content',
                'title' => 'Create ' . count($missingPages) . " Dedicated Page(s) That {$compDomain} Ranks With",
                'your_current' => 'No matching dedicated landing/guide pages found on ' . $website->domain,
                'competitor_benchmark' => "{$compDomain} has dedicated pages: " . implode(' • ', $sampleTitles),
                'why_it_matters' => 'Dedicated service and topic pages capture specific commercial searches far better than trying to rank one homepage for everything.',
                'action_steps' => array_map(
                    fn ($p) => "Build a focused, higher-quality page covering \"{$p['title']}\" (reference: {$p['url']}).",
                    array_slice($missingPages, 0, 4)
                ),
                'expected_impact' => 'Captures net-new non-branded search traffic currently going exclusively to ' . $compDomain . '.',
                'effort' => 'medium',
                'affected_url' => $website->target_url,
            ];
        }

        // 4. Google Keyword Battles where Competitor Outranks Target
        $lostBattles = array_values(array_filter($keywordBattles, fn ($b) => $b['competitor_position'] !== null && ($b['target_position'] === null || $b['target_position'] > $b['competitor_position'])));
        if (!empty($lostBattles)) {
            $topBattle = $lostBattles[0];
            $kwList = implode(', ', array_map(fn ($b) => "\"{$b['keyword']}\" (them #{$b['competitor_position']} vs you " . ($b['target_position'] ? "#{$b['target_position']}" : 'unranked') . ')', array_slice($lostBattles, 0, 3)));
            $items[] = [
                'id' => 'keyword-outranked',
                'priority' => 'critical',
                'category' => 'content',
                'title' => "Close Google Ranking Gaps on " . count($lostBattles) . " Core Commercial Keyword(s)",
                'your_current' => $topBattle['target_position'] ? "Ranked #{$topBattle['target_position']} on \"{$topBattle['keyword']}\"" : "Not in Google Top 20 for \"{$topBattle['keyword']}\"",
                'competitor_benchmark' => "{$compDomain} ranks #{$topBattle['competitor_position']} for \"{$topBattle['keyword']}\"",
                'why_it_matters' => "Directly observed on live Google SERP: {$kwList}.",
                'action_steps' => [
                    "Include the exact primary keyword \"{$topBattle['keyword']}\" naturally in Title Tag, H1, first 100 words, and URL slug.",
                    'Match or exceed the search intent and section depth of the page ranking at ' . ($topBattle['competitor_url'] ?: $compDomain) . '.',
                    'Add 3–5 descriptive internal links pointing to your target landing page.',
                ],
                'expected_impact' => 'Directly moves high-intent commercial searches from competitor clicks to your website.',
                'effort' => 'medium',
                'affected_url' => $topBattle['target_url'] ?: $website->target_url,
            ];
        }

        // 5. Server Speed / Load Time Gap
        $tSpeed = $tSig['load_time_ms'] ?? null;
        $cSpeed = $cSig['load_time_ms'] ?? null;
        if ($tSpeed !== null && (($cSpeed !== null && $tSpeed > $cSpeed + 250) || $tSpeed > 1500)) {
            $items[] = [
                'id' => 'speed-optimization',
                'priority' => $tSpeed > 2000 ? 'high' : 'medium',
                'category' => 'technical',
                'title' => "Improve Page Load Speed ({$tSpeed}ms vs {$compDomain} " . ($cSpeed ? "{$cSpeed}ms" : 'target <800ms') . ')',
                'your_current' => "{$tSpeed} ms response time",
                'competitor_benchmark' => $cSpeed ? "{$cSpeed} ms on {$compDomain}" : '<800 ms recommended benchmark',
                'why_it_matters' => 'Slower TTFB and page loads hurt Core Web Vitals (LCP), mobile bounce rate, and Google crawl efficiency.',
                'action_steps' => [
                    'Enable CDN edge caching / full-page caching and Brotli/Gzip compression.',
                    'Convert hero images to WebP/AVIF with explicit width/height attributes and preload the LCP image.',
                    'Defer non-critical third-party JavaScript and unused CSS.',
                ],
                'expected_impact' => 'Faster mobile load experience, better Core Web Vitals, and higher lead conversion.',
                'effort' => 'medium',
                'affected_url' => $website->target_url,
            ];
        }

        // 6. Meta Title / Description Optimization
        $tTitleLen = isset($tSig['title_length']) ? (int) $tSig['title_length'] : (isset($tSig['page_title']) ? mb_strlen((string) $tSig['page_title']) : 0);
        $tMetaLen = isset($tSig['meta_description_length']) ? (int) $tSig['meta_description_length'] : (isset($tSig['meta_description']) ? mb_strlen((string) $tSig['meta_description']) : 0);
        if ($tTitleLen === 0 || $tTitleLen < 25 || $tTitleLen > 68 || $tMetaLen < 70 || $tMetaLen > 170) {
            $items[] = [
                'id' => 'meta-optimization',
                'priority' => 'high',
                'category' => 'metadata',
                'title' => 'Optimize Homepage Meta Title & Description for Higher Google CTR',
                'your_current' => "Title: {$tTitleLen} chars | Meta Description: {$tMetaLen} chars",
                'competitor_benchmark' => "Ideal: Title 35–60 chars, Meta Description 120–155 chars ({$compDomain}: " . ($cSig['title_length'] ?? 0) . ' / ' . ($cSig['meta_description_length'] ?? 0) . ' chars)',
                'why_it_matters' => 'Truncated or missing titles/descriptions reduce organic click-through rate on Google SERPs.',
                'action_steps' => [
                    'Keep your homepage <title> between 45–60 characters with primary service + target city/country + brand name.',
                    'Write a compelling 135–155 character <meta name="description"> with clear value proof and call-to-action.',
                ],
                'expected_impact' => 'Immediate improvement in search snippet appearance and organic CTR.',
                'effort' => 'low',
                'affected_url' => $website->target_url,
            ];
        }

        // 7. Sitemap / Indexed Page Coverage Gap
        $tIdx = $tSnap->indexed_pages_count ?? ($tSig['google_indexed_sample_count'] ?? 0);
        $cIdx = $cSnap->indexed_pages_count ?? ($cSig['google_indexed_sample_count'] ?? 0);
        if ($cIdx > $tIdx + 3 || empty($tSig['has_sitemap']) && !empty($cSig['has_sitemap'])) {
            $items[] = [
                'id' => 'index-coverage',
                'priority' => 'high',
                'category' => 'technical',
                'title' => "Expand Indexed Page Footprint & Submit XML Sitemap ({$tIdx} vs {$cIdx} pages)",
                'your_current' => "{$tIdx} indexed/discovered pages",
                'competitor_benchmark' => "{$cIdx} indexed/discovered pages on {$compDomain}",
                'why_it_matters' => 'More indexable, high-value service/location/guide pages multiply your entry points in Google Search.',
                'action_steps' => [
                    'Verify /sitemap.xml is live, clean, and submitted in Google Search Console.',
                    'Ensure every core service and location page returns HTTP 200 with self-referencing canonical tags.',
                    'Publish supporting educational guides/blog articles that internally link back to service pages.',
                ],
                'expected_impact' => 'Broader organic keyword footprint and faster discovery of new pages by Googlebot.',
                'effort' => 'medium',
                'affected_url' => $website->target_url,
            ];
        }

        // 8. Public Web Mentions / Citation Outreach Gap
        if (!empty($citationOpportunities)) {
            $sampleSites = implode(', ', array_slice(array_map(fn ($m) => $m['domain'], $citationOpportunities), 0, 4));
            $items[] = [
                'id' => 'citation-mentions',
                'priority' => 'medium',
                'category' => 'links',
                'title' => 'Earn Brand Listings & Citations on ' . count($citationOpportunities) . " Site(s) Mentioning {$compDomain}",
                'your_current' => ($tSig['web_mentions_count'] ?? 0) . ' public Google web mentions detected',
                'competitor_benchmark' => ($cSig['web_mentions_count'] ?? count($citationOpportunities)) . " public mentions including: {$sampleSites}",
                'why_it_matters' => 'External brand citations, local business directories, and press mentions build local entity trust and referral traffic.',
                'action_steps' => [
                    "Claim or submit your business profile on the referring platforms mentioning {$compDomain}: {$sampleSites}.",
                    'Keep Name, Address, Phone (NAP), and website URL 100% consistent across every listing.',
                ],
                'expected_impact' => 'Strengthens local SEO authority, brand search trust, and referral backlinks.',
                'effort' => 'low',
                'affected_url' => $website->target_url,
            ];
        }

        // 9. Image Alt-Text / Open Graph / FAQ / Blog Quick Wins
        $tAlt = $tSig['image_alt_coverage_pct'] ?? 100;
        if ($tAlt < 85) {
            $items[] = [
                'id' => 'image-alt',
                'priority' => 'medium',
                'category' => 'technical',
                'title' => "Fix Missing Image Alt Text ({$tAlt}% coverage)",
                'your_current' => "{$tAlt}% of images have alt attributes",
                'competitor_benchmark' => ($cSig['image_alt_coverage_pct'] ?? 100) . "% on {$compDomain}",
                'why_it_matters' => 'Descriptive alt text improves image search visibility and WCAG accessibility.',
                'action_steps' => [
                    'Add concise, descriptive alt attributes to all service and team photos using natural keywords.',
                ],
                'expected_impact' => 'Better Google Image visibility and improved on-page accessibility score.',
                'effort' => 'low',
                'affected_url' => $website->target_url,
            ];
        }

        if (empty($tSig['has_blog_section']) && !empty($cSig['has_blog_section'])) {
            $items[] = [
                'id' => 'blog-hub',
                'priority' => 'medium',
                'category' => 'content',
                'title' => "Launch a Blog / Resource Hub (Active on {$compDomain})",
                'your_current' => 'No prominent blog/guides section detected in navigation',
                'competitor_benchmark' => "{$compDomain} links to a dedicated blog/resources hub",
                'why_it_matters' => 'Educational guides attract top-of-funnel families researching solutions before they book.',
                'action_steps' => [
                    'Create a /blog or /guides section and publish 2–4 local, question-focused articles per month using Content Studio.',
                    'Interlink each guide directly to your primary service booking pages.',
                ],
                'expected_impact' => 'Compounding organic traffic growth and higher topical authority.',
                'effort' => 'medium',
                'affected_url' => $website->target_url,
            ];
        }

        // Sort by priority: critical -> high -> medium -> low
        $prioRank = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        usort($items, fn ($a, $b) => ($prioRank[$a['priority']] ?? 9) <=> ($prioRank[$b['priority']] ?? 9));

        return $items;
    }

    private function collectHeadToHeadKeywords(
        Website $website,
        CompetitorDomain $competitor,
        CompetitorDomainSnapshot $tSnap,
        CompetitorDomainSnapshot $cSnap,
        string $country,
        string $language,
        bool $forceRefresh
    ): array {
        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $compDomain = $competitor->domain;

        // 1. Pull any already-stored CompetitorKeyword records that include this competitor
        $existing = CompetitorKeyword::where('website_id', $website->id)
            ->orderByDesc('opportunity_score')
            ->limit(40)
            ->get()
            ->filter(fn ($k) => isset(($k->competitor_positions ?? [])[$compDomain]));

        // 2. If none exist yet and a SERP provider (like Serper.dev) is connected, run 3 live head-to-head Google checks!
        if (($existing->isEmpty() || $forceRefresh) && $this->providerManager->hasExternalProviderFor('serp')) {
            $serpProvider = $this->providerManager->resolveForCapability('serp');
            $seedQueries = $this->buildBattleQueries($website, $competitor, $tSnap, $cSnap, $country);

            foreach (array_slice($seedQueries, 0, 3) as $kw) {
                $serpRes = $this->providerManager->cachedCall(
                    $website,
                    'serp',
                    "{$serpProvider->getName()}:battle:{$kw}:{$country}:{$language}",
                    "battle_serp:{$kw}",
                    fn () => $serpProvider->fetchSerp($kw, $country, $language, 'desktop'),
                    $forceRefresh
                );

                if (!($serpRes['available'] ?? false)) {
                    continue;
                }

                $targetPos = null;
                $targetUrl = null;
                $compPos = null;
                $compUrl = null;

                foreach ($serpRes['organic_results'] ?? [] as $row) {
                    $d = CompetitorDomain::normalizeDomain((string) ($row['domain'] ?? ''));
                    if ($targetPos === null && ($d === $targetDomain || str_ends_with($d, '.' . $targetDomain))) {
                        $targetPos = (int) $row['position'];
                        $targetUrl = $row['url'] ?? null;
                    }
                    if ($compPos === null && ($d === $compDomain || str_ends_with($d, '.' . $compDomain))) {
                        $compPos = (int) $row['position'];
                        $compUrl = $row['url'] ?? null;
                    }
                }

                if ($targetPos !== null || $compPos !== null) {
                    $record = CompetitorKeyword::where('website_id', $website->id)
                        ->where('keyword', $kw)
                        ->where('country', strtoupper($country))
                        ->where('language', strtolower($language))
                        ->first();

                    $compPositions = is_array($record?->competitor_positions) ? $record->competitor_positions : [];
                    if ($compPos !== null) {
                        $compPositions[$compDomain] = ['position' => $compPos, 'url' => $compUrl];
                    }

                    $finalTargetPos = $targetPos ?? $record?->target_position;
                    $gapType = KeywordGapService::classifyGap($finalTargetPos, $compPositions);
                    $oppScore = KeywordGapService::calculateOpportunityScore($gapType, null, null, $finalTargetPos, $compPositions);

                    CompetitorKeyword::updateOrCreate(
                        [
                            'website_id' => $website->id,
                            'keyword' => $kw,
                            'country' => strtoupper($country),
                            'language' => strtolower($language),
                        ],
                        [
                            'organization_id' => $website->organization_id,
                            'search_intent' => 'commercial',
                            'target_position' => $finalTargetPos,
                            'target_url' => $targetUrl ?? $record?->target_url,
                            'competitor_positions' => $compPositions,
                            'gap_type' => $gapType,
                            'serp_features' => $serpRes['serp_features'] ?? [],
                            'opportunity_score' => $oppScore,
                            'data_source' => $serpProvider->getName(),
                            'data_classification' => 'third_party_estimate',
                            'last_updated_at' => now(),
                        ]
                    );
                }
            }

            $existing = CompetitorKeyword::where('website_id', $website->id)
                ->orderByDesc('opportunity_score')
                ->limit(40)
                ->get()
                ->filter(fn ($k) => isset(($k->competitor_positions ?? [])[$compDomain]));
        }

        return $existing->take(15)->map(function (CompetitorKeyword $k) use ($compDomain) {
            $cInfo = ($k->competitor_positions ?? [])[$compDomain] ?? [];
            $cPos = is_array($cInfo) ? ($cInfo['position'] ?? null) : null;
            $cUrl = is_array($cInfo) ? ($cInfo['url'] ?? null) : null;
            $winner = 'tie';
            if ($cPos !== null && ($k->target_position === null || $k->target_position > $cPos)) {
                $winner = 'competitor';
            } elseif ($k->target_position !== null && ($cPos === null || $k->target_position < $cPos)) {
                $winner = 'target';
            }

            return [
                'id' => $k->id,
                'keyword' => $k->keyword,
                'gap_type' => $k->gap_type,
                'search_volume' => $k->search_volume,
                'target_position' => $k->target_position,
                'target_url' => $k->target_url,
                'competitor_position' => $cPos,
                'competitor_url' => $cUrl,
                'winner' => $winner,
                'data_source' => $k->data_source,
            ];
        })->values()->toArray();
    }

    private function buildBattleQueries(
        Website $website,
        CompetitorDomain $competitor,
        CompetitorDomainSnapshot $tSnap,
        CompetitorDomainSnapshot $cSnap,
        string $country
    ): array {
        $queries = [];

        // Pull from competitor's discovery run if matched_queries exist
        $latestRun = \App\Models\CompetitorDiscoveryRun::where('website_id', $website->id)
            ->orderByDesc('created_at')
            ->first();
        if ($latestRun && is_array($latestRun->candidates)) {
            foreach ($latestRun->candidates as $cand) {
                if (($cand['domain'] ?? '') === $competitor->domain && !empty($cand['matched_queries'])) {
                    foreach ($cand['matched_queries'] as $mq) {
                        $queries[] = trim((string) $mq);
                    }
                }
            }
        }

        // Pull from GSC top keywords on target snapshot
        foreach (array_slice($tSnap->top_keywords ?? [], 0, 2) as $tk) {
            if (!empty($tk['keyword'])) {
                $queries[] = trim((string) $tk['keyword']);
            }
        }

        // Fallback to competitor page title cleaned
        if (empty($queries) && !empty($cSnap->crawled_signals['page_title'])) {
            $title = trim(preg_replace('/\s*[\|\-—:]\s*.*$/', '', (string) $cSnap->crawled_signals['page_title']));
            if (strlen($title) >= 6) {
                $queries[] = Str::limit($title, 55, '');
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    private function isTopicCovered(string $topic, string $targetCorpus): bool
    {
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($topic)) ?: [],
            fn ($w) => strlen($w) >= 4 && !in_array($w, ['home', 'page', 'about', 'service', 'services', 'best', 'bangladesh', 'dhaka'], true)
        ));
        if (empty($words)) {
            return true;
        }
        $hits = 0;
        foreach ($words as $w) {
            if (str_contains($targetCorpus, $w)) {
                $hits++;
            }
        }
        return ($hits / count($words)) >= 0.6;
    }
}
