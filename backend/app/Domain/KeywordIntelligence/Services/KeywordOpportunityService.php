<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Models\CrawledPage;
use App\Models\KeywordProject;
use App\Models\KiKeyword;
use App\Models\Website;

/**
 * Transparent, Configurable AI Keyword Opportunity Scoring Engine (0–100).
 *
 * Scoring weights are configurable per website/project and documented clearly.
 * Missing third-party metrics are never invented — when unavailable, their weight is
 * redistributed across verified available signals and `data_confidence` is downgraded transparently.
 * Opportunity scores represent prioritization guidance, NOT guaranteed ranking probabilities.
 */
class KeywordOpportunityService
{
    public const DEFAULT_WEIGHTS = [
        'demand' => 25,
        'difficulty' => 20,
        'relevance' => 20,
        'intent' => 15,
        'position_potential' => 10,
        'competitor_gap' => 5,
        'trend' => 5,
    ];

    /**
     * Resolve effective normalized weights for a website.
     */
    public function getWeights(Website $website): array
    {
        $project = KeywordProject::where('website_id', $website->id)->first();
        $custom = is_array($project?->scoring_weights) ? $project->scoring_weights : [];
        return $this->normalizeWeights($custom);
    }

    public function normalizeWeights(array $raw): array
    {
        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $key => $defaultVal) {
            $weights[$key] = isset($raw[$key]) && is_numeric($raw[$key])
                ? max(0, min(100, (int) round((float) $raw[$key])))
                : $defaultVal;
        }
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return self::DEFAULT_WEIGHTS;
        }
        return $weights;
    }

    /**
     * Persist updated scoring weights and recalculate opportunity scores for all website keywords.
     */
    public function updateWeightsAndRescore(Website $website, array $newWeights, ?int $userId = null): array
    {
        $normalized = $this->normalizeWeights($newWeights);

        $project = KeywordProject::firstOrCreate(
            ['website_id' => $website->id],
            [
                'organization_id' => $website->organization_id,
                'created_by_user_id' => $userId,
                'name' => $website->name . ' Keyword Intelligence',
                'target_country' => strtoupper($website->target_country ?: 'US'),
                'target_language' => strtolower($website->preferred_language ?: 'en'),
            ]
        );
        $project->update(['scoring_weights' => $normalized]);

        $contextTerms = $this->extractWebsiteContextTerms($website);
        $crawledTitles = $this->extractCrawledTitles($website);
        $rescored = 0;

        KiKeyword::where('website_id', $website->id)
            ->chunkById(100, function ($chunk) use ($normalized, $contextTerms, $crawledTitles, &$rescored) {
                foreach ($chunk as $kw) {
                    $result = $this->scoreKeywordAttributes($kw->toArray(), $normalized, $contextTerms, $crawledTitles);
                    $kw->update($result);
                    $rescored++;
                }
            });

        return [
            'weights' => $normalized,
            'rescored_count' => $rescored,
        ];
    }

    /**
     * Compute transparent opportunity scoring attributes for a keyword array.
     *
     * @return array{
     *   opportunity_score: float,
     *   relevance_score: float,
     *   ranking_potential: string,
     *   competition_level: ?string,
     *   opportunity_category: string,
     *   recommended_priority: string,
     *   opportunity_reason: string,
     *   data_confidence: string
     * }
     */
    public function scoreKeywordAttributes(
        array $kw,
        array $weights,
        array $websiteContextTerms = [],
        array $crawledTitles = []
    ): array {
        $keywordText = strtolower(trim((string) ($kw['keyword'] ?? '')));
        $volume = isset($kw['search_volume']) && $kw['search_volume'] !== null ? (int) $kw['search_volume'] : null;
        $kd = isset($kw['keyword_difficulty']) && $kw['keyword_difficulty'] !== null ? (float) $kw['keyword_difficulty'] : null;
        $impressions = isset($kw['gsc_impressions']) && $kw['gsc_impressions'] !== null ? (int) $kw['gsc_impressions'] : null;
        $position = isset($kw['current_position']) && $kw['current_position'] !== null ? (float) $kw['current_position'] : null;
        $intent = strtolower((string) ($kw['search_intent'] ?? 'informational'));
        $compCoverage = (int) ($kw['competitor_coverage_count'] ?? 0);
        $trendDir = strtolower((string) ($kw['trend_direction'] ?? 'unavailable'));
        $wordCount = (int) ($kw['word_count'] ?? count(preg_split('/\s+/', $keywordText, -1, PREG_SPLIT_NO_EMPTY) ?: []));

        // Track which data pillars have verified/empirical signals
        $signals = [];
        $reasons = [];
        $verifiedPillars = 0;

        // 1. Search Demand (Provider Volume OR First-Party GSC Impressions)
        $demandScore = null;
        if ($volume !== null) {
            $verifiedPillars++;
            $demandScore = match (true) {
                $volume >= 10000 => 95.0,
                $volume >= 2500 => 84.0,
                $volume >= 500 => 72.0,
                $volume >= 100 => 58.0,
                $volume >= 20 => 42.0,
                default => 25.0,
            };
            $reasons[] = "Monthly volume " . number_format($volume);
        } elseif ($impressions !== null && $impressions > 0) {
            $verifiedPillars++;
            $demandScore = match (true) {
                $impressions >= 2000 => 92.0,
                $impressions >= 500 => 80.0,
                $impressions >= 100 => 66.0,
                $impressions >= 25 => 52.0,
                default => 38.0,
            };
            $reasons[] = number_format($impressions) . " verified GSC impressions";
        }
        if ($demandScore !== null) {
            $signals['demand'] = $demandScore;
        }

        // 2. Keyword Difficulty (only when provider supplies KD)
        $difficultyScore = null;
        $compLevel = $kw['competition_level'] ?? null;
        if ($kd !== null) {
            $verifiedPillars++;
            $difficultyScore = max(5.0, min(100.0, 100.0 - $kd));
            if ($compLevel === null) {
                $compLevel = $kd >= 68 ? 'high' : ($kd >= 38 ? 'medium' : 'low');
            }
            $reasons[] = "KD " . round($kd, 0) . "/100";
        }
        if ($difficultyScore !== null) {
            $signals['difficulty'] = $difficultyScore;
        }

        // 3. Business Relevance Score (0-100 based on website context profile, domain terms, crawled titles)
        $relevanceScore = $this->computeRelevance($keywordText, $websiteContextTerms, $wordCount);
        $signals['relevance'] = $relevanceScore;

        // 4. Search Intent Value
        $intentScore = match ($intent) {
            'transactional' => 92.0,
            'commercial' => 84.0,
            'informational' => 66.0,
            'navigational' => 52.0,
            default => 60.0,
        };
        $signals['intent'] = $intentScore;

        // 5. Ranking Position Potential (striking distance #4-#20 scores highest!)
        $hasExistingPage = $this->hasMatchingCrawledPage($keywordText, $crawledTitles);
        $positionPotentialScore = 62.0;
        if ($position !== null) {
            $verifiedPillars++;
            if ($position >= 4 && $position <= 15) {
                $positionPotentialScore = 95.0;
                $reasons[] = "Striking-distance rank #" . round($position, 1);
            } elseif ($position > 15 && $position <= 30) {
                $positionPotentialScore = 82.0;
                $reasons[] = "Page 2-3 rank #" . round($position, 1);
            } elseif ($position <= 3) {
                $positionPotentialScore = 48.0; // Already strong top-3 position
                $reasons[] = "Existing Top-3 strength (#" . round($position, 1) . ")";
            } else {
                $positionPotentialScore = 68.0;
            }
        } elseif (!$hasExistingPage) {
            $positionPotentialScore = 76.0;
            $reasons[] = "No dedicated crawled page detected (content gap)";
        }
        $signals['position_potential'] = $positionPotentialScore;

        // 6. Competitor Coverage Gap
        if ($compCoverage > 0) {
            $verifiedPillars++;
            $signals['competitor_gap'] = min(100.0, 55.0 + ($compCoverage * 15.0));
            $reasons[] = "Covered by {$compCoverage} competitor(s)";
        }

        // 7. Trend Direction (only when historical trend exists)
        if (in_array($trendDir, ['rising', 'stable', 'declining', 'seasonal'], true)) {
            $verifiedPillars++;
            $signals['trend'] = match ($trendDir) {
                'rising' => 95.0,
                'seasonal' => 74.0,
                'stable' => 65.0,
                'declining' => 30.0,
                default => 60.0,
            };
            if ($trendDir === 'rising') {
                $reasons[] = "Rising historical demand trend";
            }
        }

        // Weighted aggregation over ONLY available signals (transparent weight redistribution)
        $weightedSum = 0.0;
        $activeWeightSum = 0.0;
        foreach ($signals as $pillar => $val) {
            $w = (float) ($weights[$pillar] ?? 0);
            if ($w <= 0) {
                continue;
            }
            $weightedSum += $val * $w;
            $activeWeightSum += $w;
        }

        $score = $activeWeightSum > 0 ? round($weightedSum / $activeWeightSum, 2) : 50.0;
        $score = max(1.0, min(100.0, $score));

        // Data confidence depends strictly on how many empirical/verified data pillars exist
        $dataConfidence = match (true) {
            $verifiedPillars >= 3 => 'high',
            $verifiedPillars >= 1 => 'medium',
            default => 'low',
        };

        // Classify into the 6 required opportunity categories
        $category = $this->classifyOpportunityCategory($score, $kd, $position, $hasExistingPage, $compCoverage);

        // Ranking potential qualitative estimate (never phrased as a guarantee)
        $rankingPotential = match (true) {
            $position !== null && $position <= 3 => 'Defend Top-3 Position',
            $position !== null && $position <= 15 => 'Strong Near-Term Uplift Potential',
            $kd !== null && $kd >= 72 => 'Challenging (High Authority Required)',
            $kd !== null && $kd <= 35 && $relevanceScore >= 65 => 'Favorable Entry Potential',
            $wordCount >= 4 && $relevanceScore >= 65 => 'Targeted Long-Tail Potential',
            default => 'Moderate Potential (Verify SERP)',
        };

        // Recommended Priority P1-P4
        $priority = match (true) {
            $category === 'high_opportunity' || ($category === 'content_gap' && $score >= 72) => 'P1',
            $score >= 65 || $category === 'existing_strength' => 'P2',
            $score >= 45 => 'P3',
            default => 'P4',
        };

        if (empty($reasons)) {
            $reasons[] = ucfirst($intent) . " intent, " . round($relevanceScore) . "% topical relevance";
        }
        if ($volume === null && $kd === null) {
            $reasons[] = "External volume/KD unavailable — scored from relevance, intent & first-party signals";
        }

        return [
            'opportunity_score' => $score,
            'relevance_score' => round($relevanceScore, 2),
            'ranking_potential' => $rankingPotential,
            'competition_level' => $compLevel,
            'opportunity_category' => $category,
            'recommended_priority' => $priority,
            'opportunity_reason' => implode(' • ', array_slice($reasons, 0, 4)) . '.',
            'data_confidence' => $dataConfidence,
        ];
    }

    private function classifyOpportunityCategory(
        float $score,
        ?float $kd,
        ?float $position,
        bool $hasExistingPage,
        int $compCoverage
    ): string {
        if ($position !== null && $position <= 5.0) {
            return 'existing_strength';
        }
        if ($kd !== null && $kd >= 72.0 && ($position === null || $position > 20)) {
            return 'high_competition';
        }
        if (($position === null && !$hasExistingPage && $score >= 60.0) || ($compCoverage >= 1 && $position === null && $score >= 58.0)) {
            return 'content_gap';
        }
        if ($score >= 74.0) {
            return 'high_opportunity';
        }
        if ($score >= 52.0) {
            return 'medium_opportunity';
        }
        return 'low_opportunity';
    }

    public function extractWebsiteContextTerms(Website $website): array
    {
        $profile = $website->contextProfile;
        $parts = [
            $website->name,
            preg_replace('/\.(com|org|net|io|co|bd|uk|ai)$/i', '', (string) $website->domain),
            $profile?->business_description ?? '',
            $profile?->products_and_services ?? '',
            $profile?->target_audience ?? '',
            is_array($profile?->core_topics ?? null) ? implode(' ', $profile->core_topics) : '',
        ];

        $raw = strtolower(implode(' ', array_filter($parts)));
        $tokens = preg_split('/[^a-z0-9]+/i', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'your', 'our', 'are', 'you', 'www', 'http', 'https'];
        return array_values(array_unique(array_filter($tokens, fn ($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true))));
    }

    public function extractCrawledTitles(Website $website): array
    {
        return CrawledPage::where('website_id', $website->id)
            ->whereNotNull('page_title')
            ->limit(150)
            ->pluck('page_title')
            ->map(fn ($t) => strtolower((string) $t))
            ->toArray();
    }

    private function computeRelevance(string $keyword, array $contextTerms, int $wordCount): float
    {
        if (empty($contextTerms)) {
            return $wordCount >= 3 ? 72.0 : 64.0;
        }
        $kwTokens = preg_split('/[^a-z0-9]+/i', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kwTokens = array_values(array_filter($kwTokens, fn ($t) => mb_strlen($t) >= 3));
        if (empty($kwTokens)) {
            return 55.0;
        }

        $matches = 0;
        foreach ($kwTokens as $t) {
            foreach ($contextTerms as $ct) {
                if ($t === $ct || str_contains($ct, $t) || str_contains($t, $ct)) {
                    $matches++;
                    break;
                }
            }
        }

        $ratio = $matches / max(1, count($kwTokens));
        return min(98.0, max(35.0, round(42.0 + ($ratio * 56.0), 1)));
    }

    private function hasMatchingCrawledPage(string $keyword, array $crawledTitles): bool
    {
        if (empty($crawledTitles)) {
            return false;
        }
        $tokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn ($t) => mb_strlen($t) >= 4
        ));
        if (empty($tokens)) {
            return false;
        }

        foreach ($crawledTitles as $title) {
            $hit = 0;
            foreach ($tokens as $t) {
                if (str_contains($title, $t)) {
                    $hit++;
                }
            }
            if ($hit >= min(2, count($tokens))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return documented transparent methodology metadata for UI & API consumers.
     */
    public function getMethodology(Website $website): array
    {
        return [
            'weights' => $this->getWeights($website),
            'default_weights' => self::DEFAULT_WEIGHTS,
            'scale' => '0-100 prioritization index (not a guaranteed ranking probability)',
            'missing_data_policy' => 'When third-party search volume, difficulty, or historical trend data is unavailable, missing pillars are omitted and weights are transparently redistributed across available verified signals, lowering data_confidence accordingly.',
            'categories' => [
                'high_opportunity' => 'Score >= 74 with favorable balance of demand, relevance, and achievable competition.',
                'medium_opportunity' => 'Score 52-73; solid secondary or supporting keyword target.',
                'low_opportunity' => 'Score < 52; lower immediate priority.',
                'high_competition' => 'Verified provider Keyword Difficulty >= 72 with no current top-20 ranking.',
                'existing_strength' => 'Website already ranks in Top 5 (defend & expand).',
                'content_gap' => 'Relevant keyword with no dedicated crawled page or covered by competitors while unranked.',
            ],
        ];
    }
}
