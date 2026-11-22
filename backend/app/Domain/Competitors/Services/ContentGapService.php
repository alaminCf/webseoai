<?php

namespace App\Domain\Competitors\Services;

use App\Models\CompetitorContentGap;
use App\Models\CompetitorDomainSnapshot;
use App\Models\CompetitorKeyword;
use App\Models\ContentBrief;
use App\Models\CrawledPage;
use App\Models\Website;
use Illuminate\Support\Str;

class ContentGapService
{
    /**
     * Synthesize evidence-grounded Content Gap opportunities from competitor top pages
     * and missing/weak keyword clusters, strictly cross-checked against target CrawledPages.
     * NEVER claims a page is missing unless verified against crawled target pages.
     */
    public function analyze(Website $website): array
    {
        $targetPages = CrawledPage::where('website_id', $website->id)->get();
        $hasCrawlEvidence = $targetPages->isNotEmpty();

        // Build searchable index of verified target page titles, paths, and H1s
        $targetIndex = $targetPages->map(function (CrawledPage $p) {
            return strtolower(
                ($p->url ?? '') . ' ' .
                ($p->page_title ?? '') . ' ' .
                implode(' ', is_array($p->h1_tags) ? $p->h1_tags : [])
            );
        })->implode(' | ');

        $created = [];
        $seenTopics = [];

        // 1. Derive content gaps from Missing / Untapped / Weak Competitor Keywords
        $gapKeywords = CompetitorKeyword::where('website_id', $website->id)
            ->whereIn('gap_type', [
                CompetitorKeyword::GAP_MISSING,
                CompetitorKeyword::GAP_UNTAPPED,
                CompetitorKeyword::GAP_WEAK,
            ])
            ->orderByDesc('opportunity_score')
            ->limit(25)
            ->get();

        foreach ($gapKeywords as $kw) {
            $topicKey = strtolower(trim($kw->keyword));
            if (isset($seenTopics[$topicKey])) {
                continue;
            }
            $seenTopics[$topicKey] = true;

            $targetCovered = $hasCrawlEvidence && $this->topicMatchesText($topicKey, $targetIndex);
            $coverageStatus = !$hasCrawlEvidence
                ? 'unverified_crawl_needed'
                : ($targetCovered ? 'weak_coverage' : 'missing_verified');

            $refUrls = [];
            foreach ($kw->competitor_positions ?? [] as $compDomain => $info) {
                if (is_array($info) && !empty($info['url'])) {
                    $refUrls[] = [
                        'domain' => $compDomain,
                        'url' => $info['url'],
                        'position' => $info['position'] ?? null,
                    ];
                }
            }

            $intent = $kw->search_intent ?: 'informational';
            $contentType = match ($intent) {
                'transactional' => 'landing_page',
                'commercial' => 'comparison',
                default => 'guide',
            };

            $priority = ($kw->opportunity_score ?? 50) >= 75 ? 'high' : (($kw->opportunity_score ?? 50) >= 55 ? 'medium' : 'low');
            $titleTopic = Str::title($kw->keyword);

            $gap = CompetitorContentGap::updateOrCreate(
                [
                    'website_id' => $website->id,
                    'primary_keyword' => $kw->keyword,
                ],
                [
                    'organization_id' => $website->organization_id,
                    'topic' => $titleTopic,
                    'suggested_title' => $this->buildTitle($titleTopic, $contentType, $website->name),
                    'secondary_keywords' => [$kw->keyword . ' guide', $kw->keyword . ' tips', $kw->keyword . ' checklist'],
                    'search_intent' => $intent,
                    'suggested_content_type' => $contentType,
                    'recommended_outline' => $this->buildOutline($titleTopic, $contentType),
                    'competitor_reference_urls' => $refUrls,
                    'target_coverage_status' => $coverageStatus,
                    'rationale' => $this->buildRationale($kw->keyword, $coverageStatus, count($refUrls), $kw->data_source),
                    'priority' => $priority,
                    'confidence' => $hasCrawlEvidence ? 'high' : 'medium',
                    'data_source' => $kw->data_source,
                ]
            );
            $created[] = $gap;
        }

        // 2. Also mine competitor top_pages from domain snapshots (works even with First-Party Crawl only!)
        $compSnapshots = CompetitorDomainSnapshot::where('website_id', $website->id)
            ->where('is_target', false)
            ->orderByDesc('captured_at')
            ->get()
            ->unique('domain');

        foreach ($compSnapshots as $snap) {
            foreach (array_slice($snap->top_pages ?? [], 0, 8) as $page) {
                $url = (string) ($page['url'] ?? '');
                $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
                if ($path === '' || preg_match('/^(privacy|terms|contact|login|cart|checkout|wp-|feed)/i', $path)) {
                    continue;
                }

                $rawTopic = trim((string) ($page['title'] ?? ''));
                if ($rawTopic === '' || strlen($rawTopic) < 4) {
                    $rawTopic = Str::of(basename($path))->replace(['-', '_'], ' ')->title()->toString();
                }
                $primaryKw = strtolower(Str::limit(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $rawTopic), 60, ''));
                $primaryKw = trim(preg_replace('/\s+/', ' ', $primaryKw));
                if (strlen($primaryKw) < 4 || isset($seenTopics[$primaryKw])) {
                    continue;
                }
                $seenTopics[$primaryKw] = true;

                $targetCovered = $hasCrawlEvidence && $this->topicMatchesText($primaryKw, $targetIndex);
                if ($targetCovered) {
                    continue; // Already covered on target site
                }

                $coverageStatus = $hasCrawlEvidence ? 'missing_verified' : 'unverified_crawl_needed';

                $gap = CompetitorContentGap::updateOrCreate(
                    [
                        'website_id' => $website->id,
                        'primary_keyword' => $primaryKw,
                    ],
                    [
                        'organization_id' => $website->organization_id,
                        'topic' => Str::title($primaryKw),
                        'suggested_title' => $this->buildTitle(Str::title($primaryKw), 'guide', $website->name),
                        'secondary_keywords' => [],
                        'search_intent' => 'informational',
                        'suggested_content_type' => 'guide',
                        'recommended_outline' => $this->buildOutline(Str::title($primaryKw), 'guide'),
                        'competitor_reference_urls' => [[
                            'domain' => $snap->domain,
                            'url' => $url,
                            'title' => $page['title'] ?? $url,
                        ]],
                        'target_coverage_status' => $coverageStatus,
                        'rationale' => $this->buildRationale($primaryKw, $coverageStatus, 1, $snap->data_source),
                        'priority' => 'medium',
                        'confidence' => $hasCrawlEvidence ? 'high' : 'low',
                        'data_source' => $snap->data_source,
                    ]
                );
                $created[] = $gap;

                if (count($created) >= 20) {
                    break 2;
                }
            }
        }

        return [
            'has_target_crawl_evidence' => $hasCrawlEvidence,
            'target_pages_checked' => $targetPages->count(),
            'opportunities_found' => count($created),
            'items' => $created,
        ];
    }

    /**
     * Send an approved Content Gap opportunity directly to Content Studio as a real ContentBrief.
     */
    public function sendToContentStudio(Website $website, CompetitorContentGap $gap, int $userId): ContentBrief
    {
        $profile = $website->contextProfile;

        $brief = ContentBrief::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'created_by' => $userId,
            'target_keyword' => $gap->primary_keyword,
            'secondary_keywords' => $gap->secondary_keywords ?? [],
            'search_intent' => $gap->search_intent ?: 'informational',
            'target_audience' => $profile?->target_audience ?: 'Target website prospective customers',
            'content_type' => $gap->suggested_content_type ?: 'guide',
            'suggested_title' => $gap->suggested_title,
            'suggested_meta_description' => Str::limit(
                "Explore {$gap->topic} at {$website->name}. Actionable insights, structured comparisons, and expert guidance tailored to your needs.",
                155,
                '.'
            ),
            'word_count_target' => $gap->suggested_content_type === 'landing_page' ? 900 : 1500,
            'outline' => $gap->recommended_outline ?? [],
            'internal_link_suggestions' => [
                ['anchor' => $website->name, 'target_hint' => $website->target_url],
            ],
            'faq_items' => [
                [
                    'question' => "What should you look for in {$gap->topic}?",
                    'answer' => "Focus on verified quality, transparent pricing, and clear service standards aligned with your goals.",
                ],
            ],
            'status' => ContentBrief::STATUS_DRAFT,
        ]);

        $gap->update([
            'status' => 'sent_to_studio',
            'content_brief_id' => $brief->id,
        ]);

        return $brief;
    }

    private function topicMatchesText(string $topic, string $haystack): bool
    {
        $words = array_values(array_filter(explode(' ', $topic), fn ($w) => strlen($w) >= 4));
        if (empty($words)) {
            return false;
        }
        $hits = 0;
        foreach ($words as $w) {
            if (str_contains($haystack, $w)) {
                $hits++;
            }
        }
        return ($hits / count($words)) >= 0.65;
    }

    private function buildTitle(string $topic, string $type, string $brand): string
    {
        return match ($type) {
            'landing_page' => Str::limit("{$topic} Services | {$brand}", 60, ''),
            'comparison' => Str::limit("Best {$topic} Options & Comparison | {$brand}", 60, ''),
            default => Str::limit("Complete Guide to {$topic} | {$brand}", 60, ''),
        };
    }

    private function buildOutline(string $topic, string $type): array
    {
        if ($type === 'landing_page') {
            return [
                ['heading' => "Why Choose Our {$topic}", 'level' => 2, 'key_points' => ['Core value proposition', 'Verified service standards']],
                ['heading' => "How {$topic} Works", 'level' => 2, 'key_points' => ['Step-by-step process', 'Transparent timelines & pricing']],
                ['heading' => "Frequently Asked Questions About {$topic}", 'level' => 2, 'key_points' => ['Common buyer concerns', 'Next steps & consultation CTA']],
            ];
        }
        return [
            ['heading' => "What is {$topic}?", 'level' => 2, 'key_points' => ['Clear definition and user intent', 'Key benefits and use cases']],
            ['heading' => "Step-by-Step {$topic} Framework", 'level' => 2, 'key_points' => ['Actionable implementation checklist', 'Common mistakes to avoid']],
            ['heading' => "Key Takeaways & Next Steps", 'level' => 2, 'key_points' => ['Summary recommendations', 'Relevant internal resources']],
        ];
    }

    private function buildRationale(string $keyword, string $coverageStatus, int $refCount, string $source): string
    {
        $coverageText = match ($coverageStatus) {
            'missing_verified' => 'Verified via your Site Audit crawl that no existing page strongly targets this topic.',
            'weak_coverage' => 'Existing target pages only partially cover this topic compared to ranking competitors.',
            default => 'Target website crawl data is not yet available — run a Site Audit to verify whether an existing page already covers this topic.',
        };

        return "Observed across {$refCount} competitor reference URL(s) ({$source}) for \"{$keyword}\". {$coverageText}";
    }
}
