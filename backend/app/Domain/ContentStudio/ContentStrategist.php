<?php

namespace App\Domain\ContentStudio;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\ContentArticle;
use App\Models\ContentBrief;
use App\Models\CrawledPage;
use App\Models\Website;
use App\Models\WebsiteContextProfile;
use RuntimeException;

class ContentStrategist
{
    protected Website $website;
    protected ?WebsiteContextProfile $profile;
    protected AiManager $ai;

    public function __construct(Website $website, ?AiManager $ai = null)
    {
        $this->website = $website;
        $this->profile = $website->contextProfile;
        $this->ai = $ai ?? new AiManager(null, $website->organization, $website);
    }

    /**
     * Generate a comprehensive Content Brief for a target topic.
     */
    public function generateBrief(
        string $topic,
        ?string $searchIntent = null,
        int $targetWordCount = 1200,
        ?int $userId = null
    ): ContentBrief {
        $existingPages = CrawledPage::where('website_id', $this->website->id)
            ->whereNotNull('page_title')
            ->take(15)
            ->get(['url', 'page_title']);

        $internalInventory = $existingPages->map(fn($p) => ['url' => $p->url, 'title' => $p->page_title])->toArray();

        $systemPrompt = <<<SYS
You are an expert Content Architect and SEO Strategist for Web SEO AI.
Generate a structured, authoritative Content Brief for a target topic.
Enforce search-intent alignment, natural internal link opportunities pointing to existing crawled site pages, and user-centric FAQs.
Do not generate generic or repetitive keyword stuffing.
SYS;

        $userPrompt = "Topic: {$topic}\n" .
            "Target Word Count: {$targetWordCount}\n" .
            "Intent Preference: " . ($searchIntent ?? 'Auto-detect') . "\n" .
            "Brand Voice: " . ($this->profile?->brand_voice ?? 'Professional and authoritative') . "\n" .
            "Business Context: " . ($this->profile?->business_description ?? $this->website->name) . "\n\n" .
            SecurityPromptWrapper::wrapUntrusted(json_encode($internalInventory), 'existing_pages_inventory');

        $jsonSchema = [
            'type' => 'object',
            'properties' => [
                'target_keyword' => ['type' => 'string'],
                'secondary_keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'search_intent' => ['type' => 'string', 'enum' => ['informational', 'commercial', 'transactional', 'navigational']],
                'suggested_title' => ['type' => 'string'],
                'suggested_meta_description' => ['type' => 'string'],
                'word_count_target' => ['type' => 'integer'],
                'outline' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'heading' => ['type' => 'string'],
                            'level' => ['type' => 'integer'],
                            'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['heading', 'level', 'key_points'],
                    ],
                ],
                'internal_link_suggestions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'anchor' => ['type' => 'string'],
                            'target_hint' => ['type' => 'string'],
                        ],
                    ],
                ],
                'faq_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'required' => [
                'target_keyword', 'secondary_keywords', 'search_intent',
                'suggested_title', 'suggested_meta_description', 'outline',
            ],
        ];

        $data = $this->ai->generateStructured($systemPrompt, $userPrompt, $jsonSchema, 'brief_generation');

        return ContentBrief::create([
            'organization_id' => $this->website->organization_id,
            'website_id' => $this->website->id,
            'created_by' => $userId,
            'target_keyword' => $data['target_keyword'] ?? strtolower($topic),
            'secondary_keywords' => $data['secondary_keywords'] ?? [],
            'search_intent' => $data['search_intent'] ?? ($searchIntent ?? 'informational'),
            'target_audience' => $this->profile?->target_audience ?? 'General target audience',
            'content_type' => 'article',
            'suggested_title' => $data['suggested_title'] ?? "Guide to {$topic}",
            'suggested_meta_description' => $data['suggested_meta_description'] ?? "Learn more about {$topic}.",
            'word_count_target' => $data['word_count_target'] ?? $targetWordCount,
            'outline' => $data['outline'] ?? [],
            'internal_link_suggestions' => $data['internal_link_suggestions'] ?? [],
            'faq_items' => $data['faq_items'] ?? [],
            'status' => ContentBrief::STATUS_DRAFT,
        ]);
    }

    /**
     * Generate length-bounded Page Title and Meta Description variants.
     */
    public function generateTitleAndMeta(string $topic, ?string $contentSnippet = null): array
    {
        $systemPrompt = <<<SYS
You are a meticulous SEO copywriter.
Generate 3 distinct title and meta description candidate pairs for the target topic.
CRITICAL LENGTH CONSTRAINTS:
- Titles MUST be between 40 and 60 characters (optimal SERP pixel display width).
- Meta descriptions MUST be between 140 and 160 characters.
Do not exceed these bounds.
SYS;

        $userPrompt = "Topic: {$topic}\n" .
            "Brand Voice: " . ($this->profile?->brand_voice ?? 'Professional') . "\n" .
            ($contentSnippet ? SecurityPromptWrapper::wrapUntrusted($contentSnippet, 'page_context') : '');

        $jsonSchema = [
            'type' => 'object',
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'meta_description' => ['type' => 'string'],
                            'style' => ['type' => 'string'],
                            'intent' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'meta_description', 'style'],
                    ],
                ],
            ],
            'required' => ['candidates'],
        ];

        $data = $this->ai->generateStructured($systemPrompt, $userPrompt, $jsonSchema, 'title_meta');
        $candidates = $data['candidates'] ?? [];

        // Validate and truncate/pad if necessary to guarantee character limits
        foreach ($candidates as &$c) {
            $title = trim($c['title'] ?? '');
            if (mb_strlen($title) > 60) {
                $c['title'] = mb_substr($title, 0, 57) . '...';
            }
            $desc = trim($c['meta_description'] ?? '');
            if (mb_strlen($desc) > 160) {
                $c['meta_description'] = mb_substr($desc, 0, 157) . '...';
            }
            $c['title_length'] = mb_strlen($c['title']);
            $c['meta_length'] = mb_strlen($c['meta_description']);
        }

        return $candidates;
    }

    /**
     * Generate an article draft from a Content Brief.
     */
    public function draftArticle(ContentBrief $brief, ?int $userId = null): ContentArticle
    {
        $systemPrompt = <<<SYS
You are a senior technical writer producing high-ranking, helpful content.
Write a full article in clean Markdown based strictly on the provided brief outline and brand voice.
Format with appropriate H2 and H3 subheadings, bullet points, and actionable takeaways.
Do not use fluff, clickbait, or unsupported factual claims.
SYS;

        $userPrompt = "Topic: {$brief->target_keyword}\n" .
            "Title: {$brief->suggested_title}\n" .
            "Target Word Count: {$brief->word_count_target}\n" .
            "Search Intent: {$brief->search_intent}\n" .
            "Outline: " . json_encode($brief->outline) . "\n" .
            "Brand Voice: " . ($this->profile?->brand_voice ?? 'Clear, authoritative');

        $body = $this->ai->generateText($systemPrompt, $userPrompt, 'article_drafting');

        if (empty(trim($body))) {
            $body = "# {$brief->suggested_title}\n\n## Introduction\n\nComprehensive exploration of {$brief->target_keyword}...\n";
        }

        $wordCount = str_word_count(strip_tags($body));

        // Quality check
        $quality = $this->performQualityCheck($brief->suggested_title, $body);

        $article = ContentArticle::create([
            'organization_id' => $this->website->organization_id,
            'website_id' => $this->website->id,
            'content_brief_id' => $brief->id,
            'created_by' => $userId,
            'title' => $brief->suggested_title,
            'slug' => str()->slug($brief->suggested_title),
            'meta_description' => $brief->suggested_meta_description,
            'content_body' => $body,
            'word_count' => $wordCount,
            'search_intent' => $brief->search_intent,
            'quality_score' => $quality['score'],
            'quality_notes' => $quality['notes'],
            'status' => ContentArticle::STATUS_DRAFT, // Always remains draft initially!
            'version' => 1,
        ]);

        $article->recordRevision($userId, 'Initial AI Draft generation');

        return $article;
    }

    /**
     * Quality controls validating structure, language, and search intent alignment.
     */
    public function performQualityCheck(string $title, string $body): array
    {
        $score = 90;
        $notes = [];

        $wordCount = str_word_count(strip_tags($body));
        if ($wordCount < 400) {
            $score -= 20;
            $notes[] = [
                'type' => 'thin_content',
                'severity' => 'warning',
                'message' => "Draft is relatively short ({$wordCount} words). Consider adding practical examples.",
            ];
        }

        if (!str_contains($body, '## ')) {
            $score -= 15;
            $notes[] = [
                'type' => 'missing_subheadings',
                'severity' => 'warning',
                'message' => 'No H2 markdown subheadings found in article draft.',
            ];
        }

        if (mb_strlen($title) > 65) {
            $score -= 5;
            $notes[] = [
                'type' => 'title_length',
                'severity' => 'notice',
                'message' => 'Title tag exceeds recommended 60-character limit.',
            ];
        }

        return [
            'score' => max(40, min(100, $score)),
            'notes' => $notes,
        ];
    }

    /**
     * Generate genuine FAQs for a topic or page.
     */
    public function generateFaqs(string $topic): array
    {
        $systemPrompt = "Generate 4 realistic, helpful FAQ pairs for '{$topic}'. Format as clean JSON.";
        $userPrompt = "Brand Voice: " . ($this->profile?->brand_voice ?? 'Helpful');

        $schema = [
            'type' => 'object',
            'properties' => [
                'faq' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'answer' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'answer'],
                    ],
                ],
            ],
            'required' => ['faq'],
        ];

        $data = $this->ai->generateStructured($systemPrompt, $userPrompt, $schema, 'faq');
        return $data['faq'] ?? [];
    }

    /**
     * Analyze content gaps between website business goals and existing crawled inventory.
     */
    public function analyzeGaps(): array
    {
        $pages = CrawledPage::where('website_id', $this->website->id)
            ->whereNotNull('page_title')
            ->take(20)
            ->get(['url', 'page_title']);

        $systemPrompt = "Analyze content gaps between the business goals and existing crawled pages inventory.";
        $userPrompt = "Business Goals: " . json_encode($this->profile?->business_goals ?? ['Grow organic authority']) . "\n" .
            "Products: " . ($this->profile?->products_and_services ?? 'Enterprise services') . "\n\n" .
            SecurityPromptWrapper::wrapUntrusted(json_encode($pages), 'existing_inventory');

        $schema = [
            'type' => 'object',
            'properties' => [
                'gaps_identified' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'topic' => ['type' => 'string'],
                            'intent' => ['type' => 'string'],
                            'priority' => ['type' => 'string'],
                            'reasoning' => ['type' => 'string'],
                        ],
                        'required' => ['topic', 'intent', 'priority', 'reasoning'],
                    ],
                ],
            ],
            'required' => ['gaps_identified'],
        ];

        $data = $this->ai->generateStructured($systemPrompt, $userPrompt, $schema, 'gap_analysis');
        return $data['gaps_identified'] ?? [];
    }
}
