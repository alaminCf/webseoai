<?php

namespace App\Domain\Strategist;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\AiRecommendation;
use App\Models\AuditIssue;
use App\Models\AuditRun;
use App\Models\CrawledPage;
use App\Models\Website;
use App\Models\WebsiteContextProfile;

class RecommendationEngine
{
    protected Website $website;
    protected ?WebsiteContextProfile $profile;
    protected ?AuditRun $auditRun;
    protected AiManager $ai;

    public function __construct(Website $website, ?AiManager $ai = null)
    {
        $this->website = $website;
        $this->profile = $website->contextProfile;
        $this->auditRun = AuditRun::where('website_id', $website->id)->orderBy('id', 'desc')->first();
        $this->ai = $ai ?? new AiManager(null, $website->organization, $website);
    }

    /**
     * Generate prioritized AI SEO recommendations grounded in real audit issues and website profile.
     *
     * @return AiRecommendation[]
     */
    public function generate(): array
    {
        $issues = $this->auditRun
            ? AuditIssue::where('audit_run_id', $this->auditRun->id)->take(25)->get()
            : collect();

        $pages = CrawledPage::where('website_id', $this->website->id)->take(30)->get();

        // 1. Prepare untrusted crawl context safely
        $issuesSummary = $issues->map(function (AuditIssue $issue) {
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

        $pagesSummary = $pages->map(function (CrawledPage $p) {
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
1. Ground every recommendation strictly in the available evidence. NEVER fabricate search volumes, keyword rankings, or competitor metrics.
2. Formulate all expected benefits as estimates rather than guarantees or promises (e.g., "Estimated potential gain of 10-15%", not "Guaranteed #1 ranking").
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

        $response = $this->ai->generateStructured(
            $systemPrompt,
            $userPrompt,
            $jsonSchema,
            'recommendations'
        );

        $savedRecommendations = [];
        $rawList = $response['recommendations'] ?? [];

        foreach ($rawList as $item) {
            $impact = (int) ($item['impact_score'] ?? 5);
            $confidence = (int) ($item['confidence_score'] ?? 5);
            $effort = (int) ($item['effort_score'] ?? 5);
            $risk = (int) ($item['risk_score'] ?? 2);

            $priorityScore = AiRecommendation::calculatePriority($impact, $confidence, $effort, $risk);

            // Match issue id if affected url matches an audit issue
            $matchedIssue = $issues->firstWhere('affected_url', $item['affected_url'] ?? '');

            $rec = AiRecommendation::create([
                'organization_id' => $this->website->organization_id,
                'website_id' => $this->website->id,
                'audit_run_id' => $this->auditRun?->id,
                'audit_issue_id' => $matchedIssue?->id,
                'title' => $item['title'] ?? 'Technical SEO Improvement',
                'category' => $item['category'] ?? 'technical',
                'affected_url' => $item['affected_url'] ?? $this->website->target_url,
                'evidence' => $item['evidence'] ?? ($matchedIssue?->evidence ?? []),
                'recommended_action' => $item['recommended_action'] ?? '',
                'reasoning_summary' => $item['reasoning_summary'] ?? '',
                'expected_benefit' => $item['expected_benefit'] ?? 'Estimated improvement in organic crawlability and indexation.',
                'confidence_level' => $item['confidence_level'] ?? 'medium',
                'effort' => $item['effort'] ?? 'medium',
                'risk_level' => $item['risk_level'] ?? 'low',
                'required_permissions' => $item['required_permissions'] ?? 'editor',
                'verification_procedure' => $item['verification_procedure'] ?? 'Verify via automated re-crawl.',
                'impact_score' => $impact,
                'confidence_score' => $confidence,
                'effort_score' => $effort,
                'risk_score' => $risk,
                'priority_score' => $priorityScore,
                'status' => AiRecommendation::STATUS_PROPOSED,
            ]);

            $savedRecommendations[] = $rec;
        }

        return $savedRecommendations;
    }
}
