<?php

namespace App\Domain\Strategist;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\AiRecommendation;
use App\Models\AiUsageLog;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CrawledPage;
use App\Models\SearchOpportunity;
use App\Models\Website;
use App\Models\WebsiteContextProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RecommendationEngine
{
    protected Website $website;
    protected ?WebsiteContextProfile $profile;
    protected ?AuditRun $auditRun;
    protected AiManager $ai;
    protected ?int $userId;

    public function __construct(Website $website, ?AiManager $ai = null, ?int $userId = null)
    {
        $this->website = $website;
        $this->profile = $website->contextProfile;
        $this->auditRun = AuditRun::where('website_id', $website->id)->orderBy('id', 'desc')->first();
        $this->userId = $userId;
        $this->ai = $ai ?? new AiManager(null, $website->organization, $website, $userId);
    }

    /**
     * Generate prioritized AI SEO recommendations grounded in real audit issues,
     * crawled pages, search opportunities, and website brand profile.
     *
     * @return AiRecommendation[]
     */
    public function generate(): array
    {
        $startTime = microtime(true);

        $issues = $this->auditRun
            ? AuditIssue::where('audit_run_id', $this->auditRun->id)
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
                ->orderBy('id')
                ->take(40)
                ->get()
            : collect();

        $pages = $this->auditRun && $this->auditRun->crawl_job_id
            ? CrawledPage::where('crawl_job_id', $this->auditRun->crawl_job_id)->take(50)->get()
            : CrawledPage::where('website_id', $this->website->id)->orderByDesc('id')->take(50)->get();

        $opportunities = SearchOpportunity::where('website_id', $this->website->id)
            ->whereIn('status', ['detected', 'acknowledged'])
            ->orderByDesc('impact_score')
            ->take(5)
            ->get();

        $rawList = [];

        if ($this->hasConfiguredExternalLlm()) {
            $rawList = $this->generateWithExternalLlm($issues, $pages);
        }

        // Fall back to genuine deterministic evidence-driven synthesis (never fake example.com fixtures)
        if (empty($rawList)) {
            $rawList = $this->synthesizeFromEvidence($issues, $pages, $opportunities);

            if ($this->website->organization_id) {
                $durationMs = (int) round((microtime(true) - $startTime) * 1000);
                AiUsageLog::create([
                    'organization_id' => $this->website->organization_id,
                    'website_id' => $this->website->id,
                    'user_id' => $this->userId,
                    'provider' => 'deterministic_studio',
                    'model' => 'evidence-strategist-v3',
                    'feature' => 'recommendations',
                    'prompt_tokens' => max(120, $issues->count() * 45 + $pages->count() * 20),
                    'completion_tokens' => max(150, count($rawList) * 95),
                    'total_tokens' => max(270, $issues->count() * 45 + $pages->count() * 20 + count($rawList) * 95),
                    'estimated_cost' => 0.0,
                    'duration_ms' => max(1, $durationMs),
                    'status' => 'success',
                ]);
            }
        }

        // Clear stale unacted proposed recommendations so re-running refreshes cleanly without duplicates
        AiRecommendation::where('website_id', $this->website->id)
            ->where('status', AiRecommendation::STATUS_PROPOSED)
            ->delete();

        $savedRecommendations = [];
        $pagesByUrl = $pages->keyBy('url');

        foreach ($rawList as $item) {
            $impact = max(1, min(10, (int) ($item['impact_score'] ?? 6)));
            $confidence = max(1, min(10, (int) ($item['confidence_score'] ?? 8)));
            $effort = max(1, min(10, (int) ($item['effort_score'] ?? 3)));
            $risk = max(1, min(10, (int) ($item['risk_score'] ?? 2)));

            $priorityScore = AiRecommendation::calculatePriority($impact, $confidence, $effort, $risk);
            $affectedUrl = $item['affected_url'] ?? $this->website->target_url;
            $matchedIssue = isset($item['audit_issue_id'])
                ? $issues->firstWhere('id', $item['audit_issue_id'])
                : $issues->firstWhere('affected_url', $affectedUrl);

            $matchedPage = $pagesByUrl->get($affectedUrl);
            $evidence = is_array($item['evidence'] ?? null)
                ? $item['evidence']
                : ($matchedIssue?->evidence ?? []);

            // Attach actionable suggested metadata for CMS staging when missing
            if (empty($evidence['suggested_meta_title']) && $matchedPage) {
                $evidence['suggested_meta_title'] = $this->buildSuggestedTitle($matchedPage);
            }
            if (empty($evidence['suggested_meta_description']) && $matchedPage) {
                $evidence['suggested_meta_description'] = $this->buildSuggestedDescription($matchedPage);
            }

            $rec = AiRecommendation::create([
                'organization_id' => $this->website->organization_id,
                'website_id' => $this->website->id,
                'audit_run_id' => $this->auditRun?->id,
                'audit_issue_id' => $matchedIssue?->id,
                'crawled_page_id' => $matchedPage?->id,
                'title' => Str::limit((string) ($item['title'] ?? 'Technical SEO Improvement'), 240, ''),
                'category' => $this->normalizeCategory((string) ($item['category'] ?? 'technical')),
                'affected_url' => $affectedUrl,
                'evidence' => $evidence,
                'recommended_action' => (string) ($item['recommended_action'] ?? ''),
                'reasoning_summary' => (string) ($item['reasoning_summary'] ?? ''),
                'expected_benefit' => (string) ($item['expected_benefit'] ?? 'Estimated improvement in organic crawlability and click-through rate.'),
                'confidence_level' => $this->normalizeLevel((string) ($item['confidence_level'] ?? 'high')),
                'effort' => $this->normalizeLevel((string) ($item['effort'] ?? 'low')),
                'risk_level' => $this->normalizeLevel((string) ($item['risk_level'] ?? 'low')),
                'required_permissions' => (string) ($item['required_permissions'] ?? 'editor'),
                'verification_procedure' => (string) ($item['verification_procedure'] ?? 'Re-run Site Audit and inspect live HTML response for this URL.'),
                'impact_score' => $impact,
                'confidence_score' => $confidence,
                'effort_score' => $effort,
                'risk_score' => $risk,
                'priority_score' => $priorityScore,
                'status' => AiRecommendation::STATUS_PROPOSED,
            ]);

            $savedRecommendations[] = $rec;
        }

        // Return sorted by ICE priority score descending
        usort($savedRecommendations, fn (AiRecommendation $a, AiRecommendation $b) => $b->priority_score <=> $a->priority_score);

        return $savedRecommendations;
    }

    protected function hasConfiguredExternalLlm(): bool
    {
        $driver = strtolower((string) config('ai.default_driver', 'deterministic_studio'));
        if ($driver === 'gemini') {
            return !empty(config('ai.drivers.gemini.api_key'));
        }
        if ($driver === 'openai') {
            return !empty(config('ai.drivers.openai.api_key'));
        }
        return false;
    }

    protected function generateWithExternalLlm(Collection $issues, Collection $pages): array
    {
        $issuesSummary = $issues->take(25)->map(function (AuditIssue $issue) {
            return [
                'id' => $issue->id,
                'issue_type' => $issue->issue_type,
                'category' => $issue->category,
                'severity' => $issue->severity,
                'affected_url' => $issue->affected_url,
                'evidence' => $issue->evidence,
                'explanation' => $issue->explanation,
            ];
        })->toArray();

        $pagesSummary = $pages->take(30)->map(function (CrawledPage $p) {
            return [
                'url' => $p->url,
                'status' => $p->status_code,
                'depth' => $p->depth,
                'in_degree' => $p->in_degree,
                'title' => $p->page_title,
                'is_indexable' => $p->is_indexable,
                'in_sitemap' => $p->in_sitemap,
            ];
        })->toArray();

        $untrustedContext = SecurityPromptWrapper::wrapUntrusted(json_encode([
            'issues' => $issuesSummary,
            'pages' => $pagesSummary,
        ], JSON_PRETTY_PRINT), 'crawl_and_audit_findings');

        $profileContext = $this->profile ? [
            'business_name' => $this->profile->business_name,
            'description' => $this->profile->business_description,
            'brand_voice' => $this->profile->brand_voice,
            'goals' => $this->profile->business_goals,
            'target_audience' => $this->profile->target_audience,
        ] : [
            'business_name' => $this->website->name,
            'domain' => $this->website->domain,
        ];

        $systemPrompt = <<<SYS
You are an expert AI Technical SEO Strategist for Web SEO AI.
Your objective is to review verified site crawl metrics, detected audit issues, and business context, then synthesize high-impact, prioritized SEO action items.

CRITICAL CONSTRAINTS:
1. Ground every recommendation strictly in the available evidence and exact affected URLs from the input. NEVER fabricate URLs, search volumes, or competitor metrics.
2. Formulate all expected benefits as estimates rather than guarantees.
3. Each item MUST include: title, category, affected_url, evidence, recommended_action, reasoning_summary, expected_benefit, confidence_level (high/medium/low), effort (low/medium/high), risk_level (low/medium/high), required_permissions, verification_procedure, impact_score (1-10), confidence_score (1-10), effort_score (1-10), risk_score (1-10).
SYS;

        $userPrompt = "Website Context: " . json_encode($profileContext) . "\n\n" . $untrustedContext . "\n\nAnalyze the audit findings and return prioritized actionable recommendations.";

        $jsonSchema = [
            'type' => 'object',
            'properties' => [
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'affected_url' => ['type' => 'string'],
                            'evidence' => ['type' => 'object'],
                            'recommended_action' => ['type' => 'string'],
                            'reasoning_summary' => ['type' => 'string'],
                            'expected_benefit' => ['type' => 'string'],
                            'confidence_level' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                            'effort' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'required_permissions' => ['type' => 'string'],
                            'verification_procedure' => ['type' => 'string'],
                            'impact_score' => ['type' => 'integer'],
                            'confidence_score' => ['type' => 'integer'],
                            'effort_score' => ['type' => 'integer'],
                            'risk_score' => ['type' => 'integer'],
                        ],
                        'required' => [
                            'title', 'category', 'affected_url', 'recommended_action',
                            'reasoning_summary', 'expected_benefit', 'confidence_level',
                            'effort', 'risk_level', 'required_permissions', 'verification_procedure',
                            'impact_score', 'confidence_score', 'effort_score', 'risk_score',
                        ],
                    ],
                ],
            ],
            'required' => ['recommendations'],
        ];

        try {
            $response = $this->ai->generateStructured($systemPrompt, $userPrompt, $jsonSchema, 'recommendations');
            $items = $response['recommendations'] ?? [];
            // Reject placeholder example.com responses if fallback triggered
            return array_values(array_filter($items, function ($item) {
                $url = strtolower((string) ($item['affected_url'] ?? ''));
                return $url !== '' && !str_contains($url, 'example.com');
            }));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Synthesize genuine, evidence-backed SEO recommendations directly from the website's
     * real AuditIssues, CrawledPages, and SearchOpportunities.
     */
    protected function synthesizeFromEvidence(Collection $issues, Collection $pages, Collection $opportunities): array
    {
        $items = [];
        $pagesByUrl = $pages->keyBy('url');
        $seenKeys = [];

        // 1. Convert real AuditIssues into concrete prioritized action items
        // Group repeated site-wide issues of the same type if > 3 URLs share them, otherwise keep per-URL
        $grouped = $issues->groupBy('issue_type');

        foreach ($grouped as $issueType => $group) {
            $typeUpper = strtoupper((string) $issueType);
            $count = $group->count();

            // Keep top 2 individual URLs for high-precision metadata/broken-link fixes, or 1 consolidated site-wide item
            $takeCount = $count <= 2 ? $count : 2;
            $samples = $group->take($takeCount);

            foreach ($samples as $idx => $issue) {
                /** @var AuditIssue $issue */
                $affectedUrl = $issue->affected_url ?: $this->website->target_url;
                $dedupKey = $typeUpper . '|' . $affectedUrl;
                if (isset($seenKeys[$dedupKey])) {
                    continue;
                }
                $seenKeys[$dedupKey] = true;

                $page = $pagesByUrl->get($affectedUrl);
                $spec = $this->buildIssueActionSpec($issue, $page, $count, $idx === 0 && $count > 2);
                $items[] = $spec;

                if (count($items) >= 12) {
                    break 2;
                }
            }
        }

        // 2. Add real Search Console Opportunities (CTR / Striking Distance) when available
        foreach ($opportunities as $opp) {
            /** @var SearchOpportunity $opp */
            $targetUrl = str_starts_with((string) $opp->target_identifier, 'http')
                ? $opp->target_identifier
                : $this->website->target_url;

            $items[] = [
                'title' => $opp->headline,
                'category' => 'content',
                'affected_url' => $targetUrl,
                'evidence' => array_merge($opp->metrics_snapshot ?? [], [
                    'source' => 'google_search_console',
                    'opportunity_type' => $opp->type,
                ]),
                'recommended_action' => $opp->recommended_action,
                'reasoning_summary' => $opp->reasoning,
                'expected_benefit' => 'Estimated 15–25% uplift in organic CTR and clicks on existing search impressions.',
                'confidence_level' => 'high',
                'effort' => 'low',
                'risk_level' => 'low',
                'required_permissions' => 'editor',
                'verification_procedure' => 'Re-sync Google Search Console performance data 14 days after updating title/meta tags.',
                'impact_score' => min(10, max(6, (int) round(($opp->impact_score ?? 75) / 10))),
                'confidence_score' => 9,
                'effort_score' => 2,
                'risk_score' => 1,
            ];

            if (count($items) >= 15) {
                break;
            }
        }

        // 3. If website has not been audited yet (0 issues & 0 opportunities), create real onboarding action items for THIS domain
        if (empty($items)) {
            $brand = $this->profile?->business_name ?: $this->website->name;
            $baseUrl = $this->website->target_url;

            $items[] = [
                'title' => "Run Deep Technical Site Audit on {$this->website->domain}",
                'category' => 'technical',
                'affected_url' => $baseUrl,
                'evidence' => [
                    'domain' => $this->website->domain,
                    'audit_runs_count' => 0,
                    'suggested_meta_title' => Str::limit("{$brand} | Official Website", 60, ''),
                ],
                'recommended_action' => "Launch a full Site Audit crawl from the Site Audit tab for {$baseUrl} to detect missing meta tags, broken links, canonical mismatches, and sitemap issues.",
                'reasoning_summary' => "No crawl telemetry exists yet for {$this->website->domain}. Running a technical crawl unlocks page-level, evidence-backed recommendations.",
                'expected_benefit' => 'Establishes a full technical SEO health baseline across technical, content, indexing, and link pillars.',
                'confidence_level' => 'high',
                'effort' => 'low',
                'risk_level' => 'low',
                'required_permissions' => 'seo_manager',
                'verification_procedure' => 'Verify that the latest Audit Run shows status completed with analyzed pages > 0.',
                'impact_score' => 9,
                'confidence_score' => 10,
                'effort_score' => 1,
                'risk_score' => 1,
            ];

            $items[] = [
                'title' => "Configure Brand Context Profile for {$brand}",
                'category' => 'content',
                'affected_url' => $baseUrl,
                'evidence' => [
                    'domain' => $this->website->domain,
                    'profile_approved' => (bool) $this->profile?->approved_at,
                ],
                'recommended_action' => "Open Brand Profile above and define {$brand}'s core services, target audience, tone of voice, and SEO goals so generated titles and briefs match your brand.",
                'reasoning_summary' => 'Grounded business context prevents generic titles and aligns meta descriptions with your actual conversion goals.',
                'expected_benefit' => 'Higher relevance in AI-generated titles, meta descriptions, and content briefs.',
                'confidence_level' => 'high',
                'effort' => 'low',
                'risk_level' => 'low',
                'required_permissions' => 'editor',
                'verification_procedure' => 'Confirm Brand Profile shows "Verified & Approved Profile" status.',
                'impact_score' => 7,
                'confidence_score' => 9,
                'effort_score' => 2,
                'risk_score' => 1,
            ];
        }

        return $items;
    }

    protected function buildIssueActionSpec(AuditIssue $issue, ?CrawledPage $page, int $groupCount, bool $noteGroupCount): array
    {
        $type = strtoupper((string) $issue->issue_type);
        $severity = strtolower((string) $issue->severity);
        $url = $issue->affected_url ?: $this->website->target_url;
        $pathLabel = $this->humanizeUrlPath($url);

        $baseImpact = match ($severity) {
            'critical' => 9,
            'warning' => 7,
            default => 5,
        };
        $baseEffort = match (true) {
            str_contains($type, 'META'), str_contains($type, 'TITLE'), str_contains($type, 'ALT'), str_contains($type, 'H1') => 2,
            str_contains($type, 'CANONICAL'), str_contains($type, 'ROBOTS'), str_contains($type, 'SITEMAP'), str_contains($type, 'OG_') => 3,
            str_contains($type, 'SCHEMA'), str_contains($type, 'STRUCTURED'), str_contains($type, 'HEADER'), str_contains($type, 'HTTPS') => 4,
            default => 3,
        };
        $baseRisk = match (true) {
            str_contains($type, 'REDIRECT'), str_contains($type, 'CANONICAL'), str_contains($type, 'NOINDEX') => 4,
            str_contains($type, 'SECURITY'), str_contains($type, 'HTTPS') => 3,
            default => 1,
        };

        $readableType = Str::of($type)->replace('_', ' ')->lower()->title()->toString();
        $suffix = $noteGroupCount ? " (+{$groupCount} pages affected)" : '';

        $suggestedTitle = $page ? $this->buildSuggestedTitle($page) : Str::limit("{$pathLabel} | {$this->website->name}", 60, '');
        $suggestedDesc = $page ? $this->buildSuggestedDescription($page) : Str::limit("Explore {$pathLabel} on {$this->website->name}. Learn more about our services, solutions, and expert support.", 155, '...');

        $titleMap = [
            'STATUS_4XX' => "Fix Broken 4xx Page on {$pathLabel}",
            'STATUS_5XX' => "Resolve Server 5xx Error on {$pathLabel}",
            'BROKEN_INTERNAL_LINK' => "Repair Broken Internal Links Pointing to {$pathLabel}",
            'MISSING_TITLE' => "Add Unique SEO Title Tag to {$pathLabel}",
            'TITLE_TOO_SHORT' => "Expand Short Title Tag on {$pathLabel}",
            'TITLE_TOO_LONG' => "Trim Overlong Title Tag on {$pathLabel}",
            'DUPLICATE_TITLE' => "Differentiate Duplicate Title Tag on {$pathLabel}",
            'MISSING_META_DESCRIPTION' => "Write Compelling Meta Description for {$pathLabel}",
            'META_DESCRIPTION_TOO_SHORT' => "Expand Short Meta Description on {$pathLabel}",
            'META_DESCRIPTION_TOO_LONG' => "Trim Overlong Meta Description on {$pathLabel}",
            'MISSING_H1' => "Add Primary H1 Heading to {$pathLabel}",
            'MULTIPLE_H1' => "Consolidate Multiple H1 Tags on {$pathLabel}",
            'MISSING_CANONICAL' => "Add Self-Referencing Canonical Tag to {$pathLabel}",
            'MISSING_STRUCTURED_DATA' => "Add JSON-LD Structured Data Schema to {$pathLabel}",
            'IMAGES_MISSING_ALT' => "Add Descriptive Alt Attributes to Images on {$pathLabel}",
            'ORPHAN_PAGE' => "Add Internal Links to Orphan Page {$pathLabel}",
            'REDIRECT_CHAIN' => "Flatten Multi-Hop Redirect Chain at {$pathLabel}",
            'THIN_CONTENT' => "Expand Thin Content Depth on {$pathLabel}",
            'SLOW_RESPONSE' => "Improve Slow Server TTFB on {$pathLabel}",
            'MISSING_OPEN_GRAPH' => "Add Open Graph Social Meta Tags to {$pathLabel}",
            'MISSING_HSTS' => "Enable HSTS Security Header on {$this->website->domain}",
            'MISSING_SITEMAP' => "Publish and Reference XML Sitemap on {$this->website->domain}",
            'MISSING_ROBOTS_TXT' => "Create Valid robots.txt on {$this->website->domain}",
        ];

        $title = ($titleMap[$type] ?? "Resolve {$readableType} on {$pathLabel}") . $suffix;

        $recommendedAction = trim((string) $issue->recommended_fix);
        if ($recommendedAction === '') {
            $recommendedAction = "Inspect {$url} and resolve the detected {$readableType} issue.";
        }
        if (str_contains($type, 'TITLE') || str_contains($type, 'META_DESCRIPTION')) {
            $recommendedAction .= " Suggested Title: \"{$suggestedTitle}\" | Suggested Meta Description: \"{$suggestedDesc}\"";
        }

        $expectedBenefit = match (true) {
            str_contains($type, 'META'), str_contains($type, 'TITLE') => 'Estimated 10–25% improvement in SERP click-through rate and clearer keyword relevance.',
            str_contains($type, '4XX'), str_contains($type, '5XX'), str_contains($type, 'BROKEN'), str_contains($type, 'REDIRECT') => 'Recovers lost crawl budget, preserves internal link equity, and eliminates user dead-ends.',
            str_contains($type, 'CANONICAL'), str_contains($type, 'SITEMAP'), str_contains($type, 'ROBOTS'), str_contains($type, 'NOINDEX') => 'Ensures search engines index the preferred canonical URL cleanly without dilution.',
            str_contains($type, 'SCHEMA'), str_contains($type, 'STRUCTURED') => 'Enables rich SERP enhancements (breadcrumbs, business entity, FAQ) and stronger entity clarity.',
            str_contains($type, 'ALT'), str_contains($type, 'H1'), str_contains($type, 'THIN') => 'Strengthens on-page topical signals, accessibility compliance, and image search visibility.',
            default => 'Estimated measurable improvement in overall technical SEO health score and crawlability.',
        };

        $verificationProcedure = match (true) {
            str_contains($type, 'META'), str_contains($type, 'TITLE'), str_contains($type, 'H1'), str_contains($type, 'CANONICAL') => "Fetch {$url} via HTTP and verify updated HTML <head>/<h1> tags, then re-run Site Audit.",
            str_contains($type, '4XX'), str_contains($type, '5XX'), str_contains($type, 'BROKEN') => "Request {$url} and confirm HTTP 200 OK (or clean single-hop 301 redirect), then re-crawl.",
            str_contains($type, 'SITEMAP'), str_contains($type, 'ROBOTS') => "Open {$this->website->target_url}/robots.txt and sitemap XML directly in browser and re-run Site Audit.",
            default => "Re-run Site Audit on {$this->website->domain} and verify issue [{$issue->issue_type}] transitions to Resolved.",
        };

        $evidence = is_array($issue->evidence) ? $issue->evidence : [];
        $evidence['issue_type'] = $issue->issue_type;
        $evidence['severity'] = $issue->severity;
        $evidence['affected_pages_in_group'] = $groupCount;
        $evidence['suggested_meta_title'] = $suggestedTitle;
        $evidence['suggested_meta_description'] = $suggestedDesc;
        if ($page) {
            $evidence['current_title'] = $page->page_title;
            $evidence['current_meta_description'] = $page->meta_description;
            $evidence['http_status'] = $page->status_code;
            $evidence['word_count'] = $page->word_count;
        }

        return [
            'audit_issue_id' => $issue->id,
            'title' => $title,
            'category' => $this->normalizeCategory((string) $issue->category),
            'affected_url' => $url,
            'evidence' => $evidence,
            'recommended_action' => $recommendedAction,
            'reasoning_summary' => $issue->explanation ?: "Detected {$readableType} ({$severity}) during technical audit of {$url}.",
            'expected_benefit' => $expectedBenefit,
            'confidence_level' => 'high',
            'effort' => $baseEffort <= 2 ? 'low' : ($baseEffort <= 4 ? 'medium' : 'high'),
            'risk_level' => $baseRisk <= 2 ? 'low' : ($baseRisk <= 4 ? 'medium' : 'high'),
            'required_permissions' => $baseRisk >= 3 ? 'seo_manager' : 'editor',
            'verification_procedure' => $verificationProcedure,
            'impact_score' => $baseImpact,
            'confidence_score' => 9,
            'effort_score' => $baseEffort,
            'risk_score' => $baseRisk,
        ];
    }

    protected function buildSuggestedTitle(CrawledPage $page): string
    {
        $brand = $this->profile?->business_name ?: $this->website->name;
        $h1 = is_array($page->h1_tags) && !empty($page->h1_tags[0]) ? trim((string) $page->h1_tags[0]) : null;
        $base = $h1 ?: ($page->page_title ? trim((string) $page->page_title) : $this->humanizeUrlPath($page->url));

        if (!str_contains(strtolower($base), strtolower($brand))) {
            $candidate = "{$base} | {$brand}";
        } else {
            $candidate = $base;
        }

        return Str::limit($candidate, 58, '');
    }

    protected function buildSuggestedDescription(CrawledPage $page): string
    {
        $brand = $this->profile?->business_name ?: $this->website->name;
        $topic = is_array($page->h1_tags) && !empty($page->h1_tags[0])
            ? trim((string) $page->h1_tags[0])
            : $this->humanizeUrlPath($page->url);

        if ($this->profile && !empty($this->profile->business_description)) {
            $snippet = Str::limit(trim($this->profile->business_description), 95, '');
            return Str::limit("{$topic} by {$brand} — {$snippet}", 155, '.');
        }

        return Str::limit("Discover {$topic} at {$brand}. Explore detailed information, trusted services, and fast support tailored to your needs.", 155, '.');
    }

    protected function humanizeUrlPath(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return 'Homepage';
        }
        $segments = explode('/', $path);
        $last = end($segments) ?: $path;
        return Str::of($last)->replace(['-', '_'], ' ')->title()->toString();
    }

    protected function normalizeCategory(string $category): string
    {
        $cat = strtolower(trim($category));
        return match ($cat) {
            'technical', 'indexing', 'performance', 'security' => 'technical',
            'content', 'on_page', 'metadata' => 'content',
            'links', 'internal_links' => 'links',
            'architecture', 'structure' => 'architecture',
            default => 'technical',
        };
    }

    protected function normalizeLevel(string $level): string
    {
        $val = strtolower(trim($level));
        return in_array($val, ['high', 'medium', 'low'], true) ? $val : 'medium';
    }
}
