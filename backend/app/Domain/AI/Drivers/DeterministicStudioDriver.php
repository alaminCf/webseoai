<?php

namespace App\Domain\AI\Drivers;

use App\Domain\AI\Contracts\AiProviderInterface;
use InvalidArgumentException;
use RuntimeException;

class DeterministicStudioDriver implements AiProviderInterface
{
    protected string $model = 'deterministic-studio-v2';
    protected array $lastUsage = [
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'total_tokens' => 0,
    ];

    public function generateStructured(string $systemPrompt, string $userPrompt, array $jsonSchema, array $options = []): array
    {
        $promptLength = strlen($systemPrompt) + strlen($userPrompt);
        $promptTokens = (int) max(50, ceil($promptLength / 4));

        // Detect generation intent from user prompt / options
        $intent = $options['feature'] ?? $this->detectIntent($userPrompt);

        $result = match ($intent) {
            'recommendations' => $this->generateRecommendationsFixture($userPrompt),
            'brief_generation' => $this->generateBriefFixture($userPrompt),
            'title_meta' => $this->generateTitleMetaFixture($userPrompt),
            'outline' => $this->generateOutlineFixture($userPrompt),
            'faq' => $this->generateFaqFixture($userPrompt),
            'content_improvement' => $this->generateContentImprovementFixture($userPrompt),
            'gap_analysis' => $this->generateGapAnalysisFixture($userPrompt),
            default => $this->generateGenericSchemaCompliantFixture($jsonSchema, $userPrompt),
        };

        $completionTokens = (int) max(100, ceil(strlen(json_encode($result)) / 4));
        $this->lastUsage = [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ];

        return $result;
    }

    public function generateText(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $structured = $this->generateStructured($systemPrompt, $userPrompt, [], $options);
        return is_string($structured) ? $structured : json_encode($structured, JSON_PRETTY_PRINT);
    }

    public function getProviderName(): string
    {
        return 'deterministic_studio';
    }

    public function getModelName(): string
    {
        return $this->model;
    }

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    protected function detectIntent(string $prompt): string
    {
        $lower = strtolower($prompt);
        if (str_contains($lower, 'recommendation')) return 'recommendations';
        if (str_contains($lower, 'title') && str_contains($lower, 'meta')) return 'title_meta';
        if (str_contains($lower, 'brief')) return 'brief_generation';
        if (str_contains($lower, 'outline')) return 'outline';
        if (str_contains($lower, 'faq')) return 'faq';
        if (str_contains($lower, 'gap')) return 'gap_analysis';
        if (str_contains($lower, 'improv')) return 'content_improvement';

        return 'generic';
    }

    protected function generateRecommendationsFixture(string $prompt): array
    {
        // Extract context keywords if present
        preg_match('/Target URL:\s*([^\s\n]+)/i', $prompt, $urlMatch);
        $url = $urlMatch[1] ?? 'https://example.com/blog/article';

        return [
            'recommendations' => [
                [
                    'title' => 'Remediate Internal Broken Link Hierarchy',
                    'category' => 'links',
                    'affected_url' => $url,
                    'evidence' => [
                        'status_code' => 404,
                        'inbound_in_degree' => 3,
                    ],
                    'recommended_action' => 'Replace broken hyperlink targets with corresponding relevant active URL destinations or configure 301 redirects.',
                    'reasoning_summary' => 'Internal links returning 404 client errors erode crawl equity, break visitor conversion paths, and negatively affect topical page authority.',
                    'expected_benefit' => 'Estimated improvement of 10-15% in crawl efficiency and recovery of lost internal PageRank flow across the section.',
                    'confidence_level' => 'high',
                    'effort' => 'low',
                    'risk_level' => 'low',
                    'required_permissions' => 'editor',
                    'verification_procedure' => 'Run an immediate re-crawl of the parent page to verify the HTTP response code is 200 OK.',
                    'impact_score' => 8,
                    'confidence_score' => 9,
                    'effort_score' => 2,
                    'risk_score' => 1,
                ],
                [
                    'title' => 'Add Unique Search-Aligned Title and Primary H1 Heading',
                    'category' => 'content',
                    'affected_url' => $url,
                    'evidence' => [
                        'missing_title' => true,
                        'h1_count' => 0,
                    ],
                    'recommended_action' => 'Draft a unique, 55-character descriptive title tag and single prominent H1 heading highlighting the core subject.',
                    'reasoning_summary' => 'Search crawlers rely heavily on HTML title tags and H1 elements to deduce document relevance and match user search queries.',
                    'expected_benefit' => 'Estimated 20-30% increase in snippet click-through rate and clearer SERP ranking eligibility.',
                    'confidence_level' => 'high',
                    'effort' => 'low',
                    'risk_level' => 'low',
                    'required_permissions' => 'editor',
                    'verification_procedure' => 'Inspect rendered DOM on next crawl run to confirm exactly one title tag and one H1 are present.',
                    'impact_score' => 9,
                    'confidence_score' => 9,
                    'effort_score' => 3,
                    'risk_score' => 1,
                ],
            ],
        ];
    }

    protected function generateBriefFixture(string $prompt): array
    {
        preg_match('/Topic:\s*([^\n]+)/i', $prompt, $topicMatch);
        $topic = trim($topicMatch[1] ?? 'Technical SEO Automation');

        return [
            'target_keyword' => strtolower($topic),
            'secondary_keywords' => [
                strtolower($topic) . ' best practices',
                'seo crawler audit guide',
                'internal link architecture optimization',
            ],
            'search_intent' => 'informational',
            'suggested_title' => "Complete Guide to {$topic}: Modern Framework & Tactics",
            'suggested_meta_description' => "Learn how {$topic} drives organic growth. Discover actionable strategies, site audit checklists, and architecture best practices.",
            'word_count_target' => 1450,
            'outline' => [
                [
                    'heading' => "What is {$topic} and Why It Matters in 2026",
                    'level' => 2,
                    'key_points' => [
                        'Foundational definitions and core ranking mechanics',
                        'Impact on crawl budget and algorithmic discovery',
                    ],
                ],
                [
                    'heading' => "Step-by-Step Implementation Framework",
                    'level' => 2,
                    'key_points' => [
                        'Auditing current site infrastructure and link paths',
                        'Resolving broken links, redirect chains, and indexing blocks',
                        'Optimizing title tags, meta descriptions, and heading structure',
                    ],
                ],
                [
                    'heading' => "Common Pitfalls and How to Avoid Them",
                    'level' => 2,
                    'key_points' => [
                        'Keyword cannibalization and duplicate title tags',
                        'Orphan pages left disconnected from navigation',
                    ],
                ],
            ],
            'internal_link_suggestions' => [
                ['anchor' => 'technical SEO crawler', 'target_hint' => 'Auditing module'],
                ['anchor' => 'website health score', 'target_hint' => 'Dashboard overview'],
            ],
            'faq_items' => [
                [
                    'question' => "How often should you perform {$topic}?",
                    'answer' => "Continuous weekly audits are recommended for active publishing sites, with immediate crawls triggered after structural site deployments.",
                ],
                [
                    'question' => "What is the single most common technical SEO issue?",
                    'answer' => "Broken internal hyperlinks and missing meta descriptions remain the most prevalent low-hanging fruit across web properties.",
                ],
            ],
        ];
    }

    protected function generateTitleMetaFixture(string $prompt): array
    {
        preg_match('/Topic:\s*([^\n]+)/i', $prompt, $topicMatch);
        $topic = trim($topicMatch[1] ?? 'Enterprise SEO Solutions');

        return [
            'candidates' => [
                [
                    'title' => "{$topic} | Best Practices & Architecture Guide", // ~48 chars
                    'meta_description' => "Explore proven strategies for {$topic}. Audit site health, resolve internal link bottlenecks, and boost organic visibility with automated tooling.", // ~152 chars
                    'style' => 'authoritative',
                    'intent' => 'commercial',
                ],
                [
                    'title' => "Mastering {$topic}: Essential Tactics & Checklists", // ~51 chars
                    'meta_description' => "A practical guide to {$topic}. Learn step-by-step techniques to audit crawl graphs, fix indexing issues, and drive high-intent organic search traffic.", // ~153 chars
                    'style' => 'educational',
                    'intent' => 'informational',
                ],
                [
                    'title' => "Modern {$topic} Platform — Web SEO AI", // ~40 chars
                    'meta_description' => "Discover how automated {$topic} elevates agency workflows. Detect critical technical defects and streamline multi-tenant content optimization.", // ~146 chars
                    'style' => 'brand_focused',
                    'intent' => 'transactional',
                ],
            ],
        ];
    }

    protected function generateOutlineFixture(string $prompt): array
    {
        return [
            'sections' => [
                [
                    'heading' => 'Introduction and Core Challenges',
                    'level' => 2,
                    'summary' => 'Contextual background outlining user pain points.',
                ],
                [
                    'heading' => 'Key Architectural Considerations',
                    'level' => 2,
                    'summary' => 'Deep dive into structural best practices.',
                ],
                [
                    'heading' => 'Practical Action Items',
                    'level' => 2,
                    'summary' => 'Step-by-step resolution roadmap.',
                ],
            ],
        ];
    }

    protected function generateFaqFixture(string $prompt): array
    {
        return [
            'faq' => [
                [
                    'question' => 'How does an asynchronous crawler prevent server overload?',
                    'answer' => 'By enforcing polite request concurrency, honoring robots.txt crawl delays, and throttling requests with configurable pauses.',
                ],
                [
                    'question' => 'Why are orphan pages detrimental to organic search performance?',
                    'answer' => 'Search bots and users cannot discover orphan pages through site navigation, causing poor indexation and wasted crawl budget.',
                ],
            ],
        ];
    }

    protected function generateContentImprovementFixture(string $prompt): array
    {
        return [
            'summary' => 'Existing content lacks depth, primary H1 focus, and internal topical cross-linking.',
            'critique' => [
                'Thin word count below topical threshold',
                'Missing target intent alignment for informational queries',
                'No structured FAQ schema or question-answering blocks',
            ],
            'actionable_improvements' => [
                'Expand main body with 400 words explaining concrete implementation steps',
                'Insert internal links pointing to relevant subpages to pass equity',
                'Add 2 concise FAQ pairs answering long-tail queries',
            ],
        ];
    }

    protected function generateGapAnalysisFixture(string $prompt): array
    {
        return [
            'gaps_identified' => [
                [
                    'topic' => 'Conversion Rate Optimization with Technical SEO',
                    'intent' => 'commercial',
                    'priority' => 'high',
                    'reasoning' => 'The site addresses crawler basics but lacks content targeting commercial decision-makers looking to connect SEO to revenue.',
                ],
                [
                    'topic' => 'Core Web Vitals and Page Experience Deep Dive',
                    'intent' => 'informational',
                    'priority' => 'medium',
                    'reasoning' => 'Site performance metrics are tracked during audits but unrepresented in educational content offerings.',
                ],
            ],
        ];
    }

    protected function generateGenericSchemaCompliantFixture(array $schema, string $prompt): array
    {
        return [
            'status' => 'success',
            'analysis' => 'Deterministic studio analysis complete.',
            'data' => [
                'topic' => 'Technical SEO',
                'evaluated_at' => date('c'),
            ],
        ];
    }
}
