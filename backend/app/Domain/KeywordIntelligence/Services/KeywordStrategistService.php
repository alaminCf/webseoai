<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\CompetitorKeyword;
use App\Models\CrawledPage;
use App\Models\KiCluster;
use App\Models\KiKeyword;
use App\Models\SearchPerformanceRecord;
use App\Models\Website;

/**
 * Context-Aware AI Keyword Strategist.
 *
 * Uses verified workspace context (business profile, crawled pages, GSC queries,
 * competitor keyword gaps, researched keywords & clusters) to produce evidence-backed
 * seed keywords, clusters, missing coverage, pillar pages, internal links, and a
 * prioritized keyword roadmap. NEVER invents metrics or presents AI inferences as verified data.
 */
class KeywordStrategistService
{
    public function analyze(Website $website, array $options = [], ?int $userId = null): array
    {
        $profile = $website->contextProfile;
        $goal = trim((string) ($options['goal'] ?? 'Grow qualified organic search traffic and close high-intent content gaps'));
        $focusTopic = trim((string) ($options['focus_topic'] ?? ''));

        $crawledPages = CrawledPage::where('website_id', $website->id)
            ->whereNotNull('page_title')
            ->limit(25)
            ->get(['url', 'page_title'])
            ->map(fn ($p) => ['url' => $p->url, 'title' => $p->page_title])
            ->toArray();

        $gscTop = SearchPerformanceRecord::where('website_id', $website->id)
            ->whereDate('date', '>=', now()->subDays(90)->toDateString())
            ->whereNotNull('query')
            ->where('query', '!=', '')
            ->select('query')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions * 1.0) / SUM(impressions) ELSE 0 END as avg_pos')
            ->groupBy('query')
            ->orderByDesc('impressions')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'query' => $r->query,
                'clicks' => (int) $r->clicks,
                'impressions' => (int) $r->impressions,
                'avg_position' => round((float) $r->avg_pos, 1),
            ])
            ->toArray();

        $competitorGaps = CompetitorKeyword::where('website_id', $website->id)
            ->whereNull('target_position')
            ->orderByDesc('search_volume')
            ->limit(20)
            ->get(['keyword', 'search_volume', 'keyword_difficulty', 'search_intent'])
            ->toArray();

        $researchedKeywords = KiKeyword::where('website_id', $website->id)
            ->orderByDesc('opportunity_score')
            ->limit(35)
            ->get(['keyword', 'search_volume', 'keyword_difficulty', 'search_intent', 'opportunity_score', 'opportunity_category', 'current_position', 'gsc_impressions', 'data_source'])
            ->toArray();

        $existingClusters = KiCluster::where('website_id', $website->id)
            ->orderByDesc('opportunity_score')
            ->limit(10)
            ->get(['name', 'primary_keyword', 'search_intent', 'keyword_count', 'opportunity_score'])
            ->toArray();

        $evidencePacket = [
            'website' => [
                'name' => $website->name,
                'domain' => $website->domain,
                'country' => $website->target_country ?: 'US',
                'language' => $website->preferred_language ?: 'en',
                'business_description' => $profile?->business_description,
                'products_and_services' => $profile?->products_and_services,
                'target_audience' => $profile?->target_audience,
                'core_topics' => $profile?->core_topics ?? [],
            ],
            'goal' => $goal,
            'focus_topic' => $focusTopic,
            'crawled_pages' => $crawledPages,
            'gsc_performance' => $gscTop,
            'competitor_keyword_gaps' => $competitorGaps,
            'researched_keywords' => $researchedKeywords,
            'existing_clusters' => $existingClusters,
        ];

        $ai = new AiManager(null, $website->organization, $website, $userId);

        $systemPrompt = <<<SYS
You are the AI Keyword Strategist for Web SEO AI.
Analyze the provided workspace evidence packet and generate a structured, evidence-backed keyword strategy.
STRICT INTEGRITY RULES:
1. NEVER invent or fabricate search volume, keyword difficulty, CPC, or traffic numbers.
2. Every recommendation MUST cite its supporting evidence from the provided packet (GSC data, crawled pages, competitor gaps, or researched keywords).
3. Clearly treat all strategic suggestions as AI-inferred recommendations, never as verified provider metrics.
SYS;

        $userPrompt = "Generate a comprehensive AI Keyword Strategy for {$website->name} ({$website->domain}).\n"
            . "Goal: {$goal}\n"
            . ($focusTopic !== '' ? "Focus Topic: {$focusTopic}\n" : '')
            . SecurityPromptWrapper::wrapUntrusted(json_encode($evidencePacket), 'keyword_strategy_evidence');

        $schema = [
            'type' => 'object',
            'properties' => [
                'executive_summary' => ['type' => 'string'],
                'recommended_seed_keywords' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'seed' => ['type' => 'string'],
                            'intent' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],
                        'required' => ['seed', 'intent', 'evidence'],
                    ],
                ],
                'missing_keyword_coverage' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'primary_keyword' => ['type' => 'string'],
                            'secondary_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'search_intent' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],
                        'required' => ['topic', 'primary_keyword', 'evidence'],
                    ],
                ],
                'pillar_page_recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'pillar_title' => ['type' => 'string'],
                            'primary_keyword' => ['type' => 'string'],
                            'supporting_topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'search_intent' => ['type' => 'string'],
                            'evidence' => ['type' => 'string'],
                        ],
                        'required' => ['pillar_title', 'primary_keyword', 'evidence'],
                    ],
                ],
                'internal_linking_opportunities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'from_page' => ['type' => 'string'],
                            'to_target' => ['type' => 'string'],
                            'suggested_anchor' => ['type' => 'string'],
                            'rationale' => ['type' => 'string'],
                        ],
                        'required' => ['from_page', 'to_target', 'suggested_anchor', 'rationale'],
                    ],
                ],
                'prioritization_roadmap' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'phase' => ['type' => 'string'],
                            'priority' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                            'target_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'evidence' => ['type' => 'string'],
                        ],
                        'required' => ['phase', 'priority', 'action', 'evidence'],
                    ],
                ],
            ],
            'required' => ['executive_summary', 'recommended_seed_keywords', 'pillar_page_recommendations', 'prioritization_roadmap'],
        ];

        $aiResult = $ai->generateStructured($systemPrompt, $userPrompt, $schema, 'ai_keyword_strategist');

        // Ensure complete structured output even when DeterministicStudioDriver fallback is active
        $strategy = $this->ensureGroundedStrategy($aiResult, $website, $evidencePacket);

        return [
            'classification' => 'ai_inferred',
            'disclaimer' => 'All strategic recommendations below are AI-inferred interpretations grounded in your persisted workspace data. No third-party search volumes or guaranteed ranking outcomes are fabricated.',
            'generated_at' => now()->toIso8601String(),
            'evidence_summary' => [
                'crawled_pages_analyzed' => count($crawledPages),
                'gsc_queries_analyzed' => count($gscTop),
                'competitor_gaps_analyzed' => count($competitorGaps),
                'researched_keywords_analyzed' => count($researchedKeywords),
                'clusters_analyzed' => count($existingClusters),
            ],
            'strategy' => $strategy,
        ];
    }

    private function ensureGroundedStrategy(array $aiResult, Website $website, array $packet): array
    {
        if (
            !empty($aiResult['executive_summary']) &&
            !empty($aiResult['recommended_seed_keywords']) &&
            !empty($aiResult['prioritization_roadmap'])
        ) {
            return $aiResult;
        }

        $researched = $packet['researched_keywords'];
        $gsc = $packet['gsc_performance'];
        $gaps = $packet['competitor_keyword_gaps'];
        $pages = $packet['crawled_pages'];
        $focus = $packet['focus_topic'] ?: $website->name;

        // Build evidence-backed seeds from actual workspace records
        $seeds = [];
        foreach (array_slice($researched, 0, 5) as $rk) {
            $seeds[] = [
                'seed' => $rk['keyword'],
                'intent' => $rk['search_intent'] ?? 'informational',
                'evidence' => "Researched keyword (Opportunity {$rk['opportunity_score']}/100, source: {$rk['data_source']}).",
            ];
        }
        if (empty($seeds)) {
            $domainCore = trim(str_replace(['www.', '-', '_', '.com', '.org', '.net', '.io', '.ai'], ' ', strtolower($website->domain)));
            $seeds = [
                ['seed' => $focus, 'intent' => 'commercial', 'evidence' => "Derived from website identity ({$website->name})."],
                ['seed' => "{$domainCore} services", 'intent' => 'transactional', 'evidence' => "Core commercial modifier for {$website->domain}."],
                ['seed' => "how to choose {$domainCore}", 'intent' => 'informational', 'evidence' => "Top-of-funnel educational demand aligned with {$website->name}."],
            ];
        }

        // Missing keyword coverage grounded in content gaps & competitor gaps
        $missing = [];
        foreach (array_slice($gaps, 0, 4) as $g) {
            $missing[] = [
                'topic' => ucwords($g['keyword']),
                'primary_keyword' => $g['keyword'],
                'secondary_keywords' => [],
                'search_intent' => $g['search_intent'] ?? 'commercial',
                'evidence' => 'Competitor ranks for this keyword while target website currently has no ranking recorded.',
            ];
        }
        if (empty($missing)) {
            foreach (array_slice(array_values(array_filter($researched, fn ($r) => ($r['opportunity_category'] ?? '') === 'content_gap')), 0, 4) as $cg) {
                $missing[] = [
                    'topic' => ucwords($cg['keyword']),
                    'primary_keyword' => $cg['keyword'],
                    'secondary_keywords' => [],
                    'search_intent' => $cg['search_intent'] ?? 'informational',
                    'evidence' => "Flagged as Content Gap (Opportunity {$cg['opportunity_score']}/100; no matching crawled page detected).",
                ];
            }
        }

        // Pillar recommendations grounded in top clusters or top researched keywords
        $pillars = [];
        foreach (array_slice($packet['existing_clusters'], 0, 3) as $cl) {
            $pillars[] = [
                'pillar_title' => "Complete Resource: " . ucwords($cl['primary_keyword']),
                'primary_keyword' => $cl['primary_keyword'],
                'supporting_topics' => ["FAQ Guide for {$cl['primary_keyword']}", "Checklist & Best Practices for {$cl['primary_keyword']}"],
                'search_intent' => $cl['search_intent'] ?? 'informational',
                'evidence' => "Existing cluster \"{$cl['name']}\" containing {$cl['keyword_count']} keyword(s) with avg opportunity {$cl['opportunity_score']}/100.",
            ];
        }
        if (empty($pillars) && !empty($researched)) {
            $top = $researched[0];
            $pillars[] = [
                'pillar_title' => ucwords($top['keyword']) . " — Comprehensive Pillar Guide",
                'primary_keyword' => $top['keyword'],
                'supporting_topics' => array_column(array_slice($researched, 1, 3), 'keyword'),
                'search_intent' => $top['search_intent'] ?? 'informational',
                'evidence' => "Highest-scoring researched keyword ({$top['opportunity_score']}/100).",
            ];
        }

        // Internal linking recommendations grounded in crawled pages
        $links = [];
        if (count($pages) >= 2 && !empty($researched)) {
            $links[] = [
                'from_page' => $pages[0]['url'],
                'to_target' => $pages[1]['url'],
                'suggested_anchor' => $researched[0]['keyword'],
                'rationale' => "Connect high-visibility page \"{$pages[0]['title']}\" to \"{$pages[1]['title']}\" using natural descriptive anchor text.",
            ];
        }

        // Prioritization roadmap
        $striking = array_values(array_filter($gsc, fn ($g) => $g['avg_position'] >= 4 && $g['avg_position'] <= 20));
        $roadmap = [
            [
                'phase' => 'Phase 1 — Quick Wins (Days 1–14)',
                'priority' => 'P1',
                'action' => !empty($striking)
                    ? 'Optimize existing pages ranking in GSC striking distance (#4–#20) for higher CTR and topical depth.'
                    : 'Run Keyword Clustering on top researched keywords and save P1 opportunities to SEO Action Plan.',
                'target_keywords' => !empty($striking)
                    ? array_column(array_slice($striking, 0, 4), 'query')
                    : array_column(array_slice($researched, 0, 4), 'keyword'),
                'evidence' => !empty($striking)
                    ? count($striking) . ' verified first-party GSC queries currently sit between positions #4 and #20.'
                    : count($researched) . ' researched keywords scored in workspace dataset.',
            ],
            [
                'phase' => 'Phase 2 — Content Gap Closure (Days 15–45)',
                'priority' => 'P2',
                'action' => 'Send high-opportunity content gap clusters to Content Studio to draft intent-aligned pillar and supporting pages.',
                'target_keywords' => array_column(array_slice($missing, 0, 4), 'primary_keyword'),
                'evidence' => count($missing) . ' uncovered keyword gap topic(s) identified without dedicated page coverage.',
            ],
            [
                'phase' => 'Phase 3 — Continuous Automation & Monitoring (Ongoing)',
                'priority' => 'P3',
                'action' => 'Activate weekly Keyword Automation workflows and track top commercial targets in Rank Checker.',
                'target_keywords' => array_column(array_slice($seeds, 0, 3), 'seed'),
                'evidence' => 'Maintains fresh discovery coverage and flags rising trends or cannibalization early.',
            ],
        ];

        return [
            'executive_summary' => "Analyzed " . count($researched) . " researched keywords, " . count($gsc) . " verified GSC queries, " . count($gaps) . " competitor gaps, and " . count($pages) . " crawled pages for {$website->name}. Focus first on striking-distance GSC improvements and intent-segmented content gap clusters.",
            'recommended_seed_keywords' => $seeds,
            'missing_keyword_coverage' => $missing,
            'pillar_page_recommendations' => $pillars,
            'internal_linking_opportunities' => $links,
            'prioritization_roadmap' => $roadmap,
        ];
    }
}
