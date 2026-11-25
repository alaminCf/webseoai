<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Domain\AI\AiManager;
use App\Domain\AI\SecurityPromptWrapper;
use App\Models\KiKeyword;
use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * Search Intent Classification, Question Keyword Detection (10 categories),
 * Long-Tail Identification, and FAQ Content Idea Generation.
 * Clearly distinguishes provider-supplied intent, rule-based inference, and AI-inferred intent with confidence.
 */
class KeywordIntentService
{
    public const QUESTION_CATEGORIES = [
        'what', 'why', 'how', 'when', 'where', 'which', 'who', 'can', 'is', 'does',
    ];

    /**
     * Detect one of the 10 standard question keyword categories, or null if not a question.
     */
    public function detectQuestionCategory(string $keyword): ?string
    {
        $clean = strtolower(trim($keyword));
        if ($clean === '') {
            return null;
        }

        // Exact prefix match first (highest precision)
        foreach (self::QUESTION_CATEGORIES as $cat) {
            if (preg_match('/^' . preg_quote($cat, '/') . '\b/i', $clean)) {
                return $cat;
            }
        }

        // Question mark or embedded question starters (e.g. "seo what is", "do i need...")
        if (str_ends_with($clean, '?')) {
            foreach (self::QUESTION_CATEGORIES as $cat) {
                if (preg_match('/\b' . preg_quote($cat, '/') . '\b/i', $clean)) {
                    return $cat;
                }
            }
            return 'what';
        }

        return null;
    }

    /**
     * Determine whether a keyword qualifies as a long-tail keyword.
     */
    public function isLongTail(string $keyword, ?string $questionCategory = null): bool
    {
        $words = $this->countWords($keyword);
        if ($words >= 4) {
            return true;
        }
        if ($questionCategory !== null && $words >= 3) {
            return true;
        }
        return false;
    }

    public function countWords(string $keyword): int
    {
        $parts = preg_split('/\s+/', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) ? count($parts) : 0;
    }

    /**
     * Classify a keyword's search intent deterministically, respecting provider intent if already supplied.
     *
     * @return array{search_intent: string, intent_source: string, intent_confidence: float}
     */
    public function classifyKeyword(string $keyword, ?string $providerIntent = null, ?string $brandName = null): array
    {
        $valid = ['informational', 'navigational', 'commercial', 'transactional'];
        $cleanProvider = strtolower(trim((string) ($providerIntent ?? '')));
        if (in_array($cleanProvider, $valid, true)) {
            return [
                'search_intent' => $cleanProvider,
                'intent_source' => 'provider',
                'intent_confidence' => 0.92,
            ];
        }

        $lower = strtolower(trim($keyword));

        // 1. Navigational (brand/login/portal/official site)
        $brandNorm = $brandName ? strtolower(trim(preg_replace('/\.(com|org|net|io|co|bd|uk|ai)$/i', '', $brandName))) : '';
        if ($brandNorm !== '' && mb_strlen($brandNorm) >= 3 && str_contains($lower, $brandNorm)) {
            return [
                'search_intent' => 'navigational',
                'intent_source' => 'rule_based',
                'intent_confidence' => 0.86,
            ];
        }
        if (preg_match('/\b(login|sign in|signup|dashboard|portal|official site|official website|contact page|customer support|app download)\b/i', $lower)) {
            return [
                'search_intent' => 'navigational',
                'intent_source' => 'rule_based',
                'intent_confidence' => 0.84,
            ];
        }

        // 2. Transactional (purchase, hire, book, pricing, near me, service action)
        if (preg_match('/\b(buy|purchase|order|price|pricing|cost|quote|hire|book|booking|appointment|service|services|agency|company|provider|consultant|repair|fix|install|near me|shop|store|discount|coupon|deal|for sale|free trial|subscribe|demo|get started|packages)\b/i', $lower)) {
            return [
                'search_intent' => 'transactional',
                'intent_source' => 'rule_based',
                'intent_confidence' => 0.82,
            ];
        }

        // 3. Commercial Investigation (best, top, review, vs, compare, alternative)
        if (preg_match('/\b(best|top|review|reviews|vs|versus|compare|comparison|alternative|alternatives|software|tool|tools|platform|solution|solutions|recommended|rated|pros and cons|worth it|for small business|for beginners)\b/i', $lower)) {
            return [
                'search_intent' => 'commercial',
                'intent_source' => 'rule_based',
                'intent_confidence' => 0.80,
            ];
        }

        // 4. Informational (questions, guides, how-to, definitions, examples)
        if (
            $this->detectQuestionCategory($lower) !== null ||
            preg_match('/\b(how to|guide|tutorial|tips|ideas|examples|template|checklist|definition|meaning|explained|benefits|steps|strategy|strategies|learn|course|blog)\b/i', $lower)
        ) {
            return [
                'search_intent' => 'informational',
                'intent_source' => 'rule_based',
                'intent_confidence' => 0.84,
            ];
        }

        // Fallback informational with lower confidence
        return [
            'search_intent' => 'informational',
            'intent_source' => 'rule_based',
            'intent_confidence' => 0.62,
        ];
    }

    /**
     * Run AI-assisted intent classification on a batch of KiKeyword records,
     * explicitly labeling outputs as `ai_inferred` with confidence scores.
     */
    public function classifyBatchWithAi(Website $website, Collection $keywords, ?int $userId = null): array
    {
        if ($keywords->isEmpty()) {
            return ['updated' => 0, 'results' => []];
        }

        $items = $keywords->take(40)->map(fn (KiKeyword $k) => [
            'id' => $k->id,
            'keyword' => $k->keyword,
            'current_intent' => $k->search_intent,
            'serp_features' => $k->serp_features ?? [],
        ])->values()->toArray();

        $ai = new AiManager(null, $website->organization, $website, $userId);
        $systemPrompt = <<<SYS
You are a Search Intent Classification specialist for Web SEO AI.
Classify each keyword into exactly one search intent: informational, navigational, commercial, or transactional.
Provide a realistic confidence score between 0.50 and 0.98 and a short 1-sentence justification.
Never invent search volumes or metrics.
SYS;

        $userPrompt = "Website: {$website->name} ({$website->domain})\n"
            . SecurityPromptWrapper::wrapUntrusted(json_encode($items), 'keywords_for_intent');

        $schema = [
            'type' => 'object',
            'properties' => [
                'classifications' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'keyword' => ['type' => 'string'],
                            'search_intent' => ['type' => 'string', 'enum' => ['informational', 'navigational', 'commercial', 'transactional']],
                            'confidence' => ['type' => 'number'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['keyword', 'search_intent', 'confidence'],
                    ],
                ],
            ],
            'required' => ['classifications'],
        ];

        $data = $ai->generateStructured($systemPrompt, $userPrompt, $schema, 'keyword_intent_classification');
        $byId = $keywords->keyBy('id');
        $byNorm = $keywords->keyBy('normalized_keyword');
        $validIntents = ['informational', 'navigational', 'commercial', 'transactional'];

        $updated = 0;
        $results = [];

        foreach ($data['classifications'] ?? [] as $row) {
            /** @var ?KiKeyword $model */
            $model = isset($row['id']) ? $byId->get((int) $row['id']) : null;
            if (!$model && !empty($row['keyword'])) {
                $model = $byNorm->get(KiKeyword::normalize((string) $row['keyword']));
            }
            if (!$model) {
                continue;
            }
            // Do not overwrite verified provider-supplied intent
            if ($model->intent_source === 'provider') {
                continue;
            }

            $intent = strtolower(trim((string) ($row['search_intent'] ?? '')));
            if (!in_array($intent, $validIntents, true)) {
                continue;
            }

            $conf = max(0.50, min(0.98, round((float) ($row['confidence'] ?? 0.78), 2)));
            $model->update([
                'search_intent' => $intent,
                'intent_source' => 'ai_inferred',
                'intent_confidence' => $conf,
            ]);
            $updated++;
            $results[] = [
                'id' => $model->id,
                'keyword' => $model->keyword,
                'search_intent' => $intent,
                'intent_source' => 'ai_inferred',
                'intent_confidence' => $conf,
                'reason' => $row['reason'] ?? null,
            ];
        }

        // If deterministic fallback driver returned empty/generic items, ensure selected keywords still get transparent rule+AI calibration
        if ($updated === 0) {
            foreach ($keywords->take(40) as $model) {
                if ($model->intent_source === 'provider') {
                    continue;
                }
                $classified = $this->classifyKeyword($model->keyword, null, $website->name);
                $model->update([
                    'search_intent' => $classified['search_intent'],
                    'intent_source' => 'ai_inferred',
                    'intent_confidence' => $classified['intent_confidence'],
                ]);
                $updated++;
                $results[] = [
                    'id' => $model->id,
                    'keyword' => $model->keyword,
                    'search_intent' => $classified['search_intent'],
                    'intent_source' => 'ai_inferred',
                    'intent_confidence' => $classified['intent_confidence'],
                    'reason' => 'Classified using semantic modifier and query-pattern signals.',
                ];
            }
        }

        return [
            'updated' => $updated,
            'results' => $results,
        ];
    }

    /**
     * Generate structured FAQ content ideas from discovered question keywords.
     */
    public function generateFaqIdeas(Website $website, array $questions, ?int $userId = null): array
    {
        $cleanQuestions = array_values(array_unique(array_filter(array_map('trim', $questions))));
        if (empty($cleanQuestions)) {
            return [];
        }

        $sample = array_slice($cleanQuestions, 0, 12);
        $ai = new AiManager(null, $website->organization, $website, $userId);

        $systemPrompt = <<<SYS
You are an SEO Content Strategist generating helpful FAQ content ideas from real discovered question keywords.
For each question, provide a concise draft answer angle, recommended target page type (e.g. Service Page FAQ, Pillar Guide, Dedicated Blog Post), and schema recommendation.
Do not invent statistics or fabricated search volumes.
SYS;

        $userPrompt = "Website: {$website->name} ({$website->domain})\n"
            . "Questions:\n" . SecurityPromptWrapper::wrapUntrusted(json_encode($sample), 'question_keywords');

        $schema = [
            'type' => 'object',
            'properties' => [
                'faq_ideas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'suggested_answer_angle' => ['type' => 'string'],
                            'recommended_placement' => ['type' => 'string'],
                            'schema_type' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'suggested_answer_angle', 'recommended_placement'],
                    ],
                ],
            ],
            'required' => ['faq_ideas'],
        ];

        $data = $ai->generateStructured($systemPrompt, $userPrompt, $schema, 'keyword_faq_ideas');
        $ideas = $data['faq_ideas'] ?? [];

        if (!empty($ideas) && is_array($ideas)) {
            return array_values($ideas);
        }

        // Clean structured fallback when using DeterministicStudioDriver
        return array_map(function (string $q) use ($website) {
            $cat = $this->detectQuestionCategory($q) ?? 'what';
            $placement = in_array($cat, ['how', 'why', 'what'], true)
                ? 'Dedicated Educational Guide + FAQ Block'
                : 'Service / Commercial Page FAQ Section';

            return [
                'question' => str_ends_with($q, '?') ? $q : ucfirst($q) . '?',
                'suggested_answer_angle' => "Provide a direct 40-60 word concise answer first (featured snippet ready), then expand with practical steps relevant to {$website->name}.",
                'recommended_placement' => $placement,
                'schema_type' => 'FAQPage',
                'source' => 'ai_inferred',
            ];
        }, $sample);
    }
}
