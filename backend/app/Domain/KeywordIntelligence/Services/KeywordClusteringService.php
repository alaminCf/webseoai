<?php

namespace App\Domain\KeywordIntelligence\Services;

use App\Domain\ContentStudio\ContentStrategist;
use App\Models\ContentBrief;
use App\Models\KiCluster;
use App\Models\KiClusterMembership;
use App\Models\KiKeyword;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dual-Method Keyword Clustering Engine (Semantic + SERP-Based).
 *
 * Strictly enforces search-intent compatibility: never merges conflicting search intents
 * (e.g. transactional vs informational) into the same content target cluster.
 * Supports full cluster lifecycle: generate, incremental attach, create, rename, merge,
 * split, add/remove keywords, export CSV, and send cluster to Content Studio.
 */
class KeywordClusteringService
{
    private const STOP_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'for', 'of', 'in', 'on', 'to', 'with', 'by', 'at', 'from',
        'is', 'are', 'was', 'be', 'do', 'does', 'can', 'how', 'what', 'why', 'when', 'where', 'which', 'who',
        'near', 'me', 'best', 'top', 'vs', 'versus', 'free', 'online', '2025', '2026', '2027',
    ];

    /**
     * Generate keyword clusters using either `semantic` or `serp` clustering method.
     */
    public function generateClusters(
        Website $website,
        string $method = 'semantic',
        array $keywordIds = [],
        bool $replaceUnsaved = true
    ): array {
        $method = $method === 'serp' ? 'serp' : 'semantic';

        $query = KiKeyword::where('website_id', $website->id);
        if (!empty($keywordIds)) {
            $query->whereIn('id', $keywordIds);
        }
        $keywords = $query->orderByDesc('opportunity_score')->limit(400)->get();

        if ($keywords->isEmpty()) {
            return [
                'method' => $method,
                'clusters_created' => 0,
                'clusters' => [],
                'notice' => 'No keywords available to cluster yet. Run Keyword Discovery first.',
            ];
        }

        // Remove previous auto-generated unsaved clusters of the same website when regenerating
        if ($replaceUnsaved) {
            KiCluster::where('website_id', $website->id)
                ->where('is_saved', false)
                ->delete();
        }

        $notice = null;
        if ($method === 'serp') {
            $withSerp = $keywords->filter(fn (KiKeyword $k) => is_array($k->serp_top_urls) && count($k->serp_top_urls) >= 3);
            if ($withSerp->count() < 2) {
                $notice = 'Fewer than 2 keywords currently have live Top-10 SERP URLs captured. Inspect SERP in Keyword Explorer or run discovery with Serper enabled for full SERP-overlap clustering; falling back to semantic grouping for remaining keywords.';
            }
            $groups = $this->groupBySerpAndSemantic($keywords);
        } else {
            $groups = $this->groupSemantically($keywords);
        }

        $created = [];
        DB::transaction(function () use ($website, $method, $groups, &$created) {
            foreach ($groups as $group) {
                if (empty($group['members'])) {
                    continue;
                }
                $cluster = $this->persistClusterFromGroup($website, $method, $group['members'], $group['similarities']);
                $created[] = $cluster->load(['memberships.keyword']);
            }
        });

        return [
            'method' => $method,
            'clusters_created' => count($created),
            'notice' => $notice,
            'clusters' => $created,
        ];
    }

    /**
     * Incrementally attach newly discovered keywords to existing intent-compatible clusters,
     * creating new clusters only when no compatible cluster matches. Used by Automation workflows.
     */
    public function attachIncrementally(Website $website, Collection $newKeywords): int
    {
        if ($newKeywords->isEmpty()) {
            return 0;
        }

        $existingClusters = KiCluster::where('website_id', $website->id)
            ->with('memberships.keyword')
            ->get();

        $touchedClusterIds = [];
        $unmatched = collect();

        foreach ($newKeywords as $kw) {
            $kwTokens = $this->meaningfulTokens($kw->keyword);
            $kwIntent = $kw->search_intent ?: 'informational';

            $bestCluster = null;
            $bestSim = 0.0;

            foreach ($existingClusters as $cluster) {
                // Intent conflict guard: never merge conflicting search intents!
                if (($cluster->search_intent ?: 'informational') !== $kwIntent) {
                    continue;
                }

                $sim = $this->jaccard($kwTokens, $this->meaningfulTokens($cluster->primary_keyword));
                if ($sim >= 0.45 && $sim > $bestSim) {
                    $bestSim = $sim;
                    $bestCluster = $cluster;
                }
            }

            if ($bestCluster) {
                KiClusterMembership::firstOrCreate(
                    ['cluster_id' => $bestCluster->id, 'keyword_id' => $kw->id],
                    ['is_primary' => false, 'similarity_score' => round($bestSim, 2)]
                );
                $touchedClusterIds[$bestCluster->id] = true;
            } else {
                $unmatched->push($kw);
            }
        }

        foreach (array_keys($touchedClusterIds) as $cid) {
            $c = KiCluster::find($cid);
            if ($c) {
                $this->recalculateClusterAggregates($c);
            }
        }

        $createdCount = 0;
        if ($unmatched->isNotEmpty()) {
            $groups = $this->groupSemantically($unmatched);
            foreach ($groups as $group) {
                if (count($group['members']) >= 1) {
                    $this->persistClusterFromGroup($website, 'semantic', $group['members'], $group['similarities']);
                    $createdCount++;
                }
            }
        }

        return count($touchedClusterIds) + $createdCount;
    }

    /**
     * Manually create a new cluster from selected keyword IDs.
     */
    public function createCluster(Website $website, string $name, array $keywordIds, ?string $suggestedPillar = null): KiCluster
    {
        $keywords = KiKeyword::where('website_id', $website->id)
            ->whereIn('id', $keywordIds)
            ->orderByDesc('search_volume')
            ->orderByDesc('opportunity_score')
            ->get();

        if ($keywords->isEmpty()) {
            throw new InvalidArgumentException('Select at least one valid keyword for this website to create a cluster.');
        }

        // Enforce intent-conflict warning/guard if mixing transactional & informational directly? Allow manual creation
        // while keeping dominant intent clear, or reject conflicting intents when explicitly enforced.
        $primary = $keywords->first();
        $cluster = KiCluster::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'name' => trim($name) ?: ucwords($primary->keyword),
            'primary_keyword' => $primary->keyword,
            'clustering_method' => 'manual',
            'search_intent' => $primary->search_intent ?: 'informational',
            'suggested_pillar_page' => $suggestedPillar ?: $this->suggestPillarTitle($primary->keyword, $primary->search_intent),
            'is_saved' => true,
        ]);

        foreach ($keywords as $idx => $kw) {
            KiClusterMembership::create([
                'cluster_id' => $cluster->id,
                'keyword_id' => $kw->id,
                'is_primary' => $idx === 0,
                'similarity_score' => 1.0,
            ]);
        }

        $this->recalculateClusterAggregates($cluster);
        return $cluster->fresh(['memberships.keyword']);
    }

    /**
     * Merge two or more clusters (guarding against conflicting search intents unless force=true).
     */
    public function mergeClusters(Website $website, array $clusterIds, ?string $newName = null, bool $force = false): KiCluster
    {
        $clusters = KiCluster::where('website_id', $website->id)
            ->whereIn('id', $clusterIds)
            ->with('memberships.keyword')
            ->get();

        if ($clusters->count() < 2) {
            throw new InvalidArgumentException('Select at least two clusters to merge.');
        }

        $intents = $clusters->pluck('search_intent')->filter()->unique()->values();
        if (!$force && $intents->count() > 1) {
            throw new InvalidArgumentException(
                'Cannot merge clusters with conflicting search intents (' . $intents->implode(', ') . '). Separate content targets should be maintained for distinct intents.'
            );
        }

        $target = $clusters->first();
        $others = $clusters->slice(1);

        DB::transaction(function () use ($target, $others, $newName) {
            foreach ($others as $other) {
                foreach ($other->memberships as $m) {
                    KiClusterMembership::firstOrCreate(
                        ['cluster_id' => $target->id, 'keyword_id' => $m->keyword_id],
                        ['is_primary' => false, 'similarity_score' => $m->similarity_score]
                    );
                }
                $other->delete();
            }

            if ($newName && trim($newName) !== '') {
                $target->name = trim($newName);
            }
            $target->is_saved = true;
            $target->save();
            $this->recalculateClusterAggregates($target);
        });

        return $target->fresh(['memberships.keyword']);
    }

    /**
     * Split selected keywords out of an existing cluster into a new cluster.
     */
    public function splitCluster(Website $website, KiCluster $source, array $moveKeywordIds, ?string $newClusterName = null): array
    {
        $moveIds = array_values(array_unique(array_map('intval', $moveKeywordIds)));
        $sourceMembers = $source->memberships()->pluck('keyword_id')->toArray();
        $validMoveIds = array_values(array_intersect($sourceMembers, $moveIds));

        if (empty($validMoveIds) || count($validMoveIds) >= count($sourceMembers)) {
            throw new InvalidArgumentException('Select a strict subset of keywords to split into a new cluster.');
        }

        $newCluster = null;
        DB::transaction(function () use ($website, $source, $validMoveIds, $newClusterName, &$newCluster) {
            KiClusterMembership::where('cluster_id', $source->id)
                ->whereIn('keyword_id', $validMoveIds)
                ->delete();

            $movedKeywords = KiKeyword::whereIn('id', $validMoveIds)
                ->orderByDesc('search_volume')
                ->orderByDesc('opportunity_score')
                ->get();
            $primary = $movedKeywords->first();

            $newCluster = KiCluster::create([
                'organization_id' => $website->organization_id,
                'website_id' => $website->id,
                'name' => ($newClusterName && trim($newClusterName) !== '') ? trim($newClusterName) : ucwords($primary->keyword),
                'primary_keyword' => $primary->keyword,
                'clustering_method' => 'manual',
                'search_intent' => $primary->search_intent ?: $source->search_intent,
                'suggested_pillar_page' => $this->suggestPillarTitle($primary->keyword, $primary->search_intent),
                'is_saved' => true,
            ]);

            foreach ($movedKeywords as $idx => $kw) {
                KiClusterMembership::create([
                    'cluster_id' => $newCluster->id,
                    'keyword_id' => $kw->id,
                    'is_primary' => $idx === 0,
                    'similarity_score' => 1.0,
                ]);
            }

            $this->recalculateClusterAggregates($source);
            $this->recalculateClusterAggregates($newCluster);
        });

        return [
            'source_cluster' => $source->fresh(['memberships.keyword']),
            'new_cluster' => $newCluster->fresh(['memberships.keyword']),
        ];
    }

    /**
     * Add keywords to an existing cluster (enforcing intent compatibility unless force=true).
     */
    public function addKeywordsToCluster(Website $website, KiCluster $cluster, array $keywordIds, bool $force = false): KiCluster
    {
        $keywords = KiKeyword::where('website_id', $website->id)->whereIn('id', $keywordIds)->get();
        foreach ($keywords as $kw) {
            if (!$force && $cluster->search_intent && $kw->search_intent && $kw->search_intent !== $cluster->search_intent) {
                throw new InvalidArgumentException(
                    "Keyword \"{$kw->keyword}\" has {$kw->search_intent} intent, which conflicts with this cluster's {$cluster->search_intent} intent."
                );
            }
            KiClusterMembership::firstOrCreate(
                ['cluster_id' => $cluster->id, 'keyword_id' => $kw->id],
                ['is_primary' => false, 'similarity_score' => 0.85]
            );
        }

        $this->recalculateClusterAggregates($cluster);
        return $cluster->fresh(['memberships.keyword']);
    }

    public function removeKeywordFromCluster(KiCluster $cluster, int $keywordId): KiCluster
    {
        KiClusterMembership::where('cluster_id', $cluster->id)
            ->where('keyword_id', $keywordId)
            ->delete();
        $this->recalculateClusterAggregates($cluster);
        return $cluster->fresh(['memberships.keyword']);
    }

    /**
     * Send a complete cluster (primary + secondary keywords + pillar context) to Content Studio as a draft brief.
     */
    public function sendClusterToContentStudio(Website $website, KiCluster $cluster, ?int $userId = null): ContentBrief
    {
        $cluster->loadMissing('memberships.keyword');
        $secondary = $cluster->memberships
            ->map(fn ($m) => $m->keyword?->keyword)
            ->filter(fn ($k) => $k && strtolower($k) !== strtolower($cluster->primary_keyword))
            ->values()
            ->take(12)
            ->toArray();

        $strategist = new ContentStrategist($website);
        $brief = $strategist->generateBrief(
            $cluster->primary_keyword,
            $cluster->search_intent ?: 'informational',
            $cluster->keyword_count >= 5 ? 2000 : 1500,
            $userId
        );

        // Attach cluster's exact secondary keywords to the draft brief
        if (!empty($secondary)) {
            $mergedSec = array_values(array_unique(array_merge($secondary, is_array($brief->secondary_keywords) ? $brief->secondary_keywords : [])));
            $brief->update([
                'secondary_keywords' => array_slice($mergedSec, 0, 15),
                'suggested_title' => $cluster->suggested_pillar_page ?: $brief->suggested_title,
            ]);
        }

        $cluster->update([
            'is_saved' => true,
            'sent_to_content_studio_brief_id' => $brief->id,
        ]);

        return $brief->fresh();
    }

    public function recalculateClusterAggregates(KiCluster $cluster): void
    {
        $members = $cluster->memberships()->with('keyword')->get();
        $keywords = $members->pluck('keyword')->filter();

        if ($keywords->isEmpty()) {
            $cluster->update([
                'keyword_count' => 0,
                'combined_search_volume' => null,
                'avg_difficulty' => null,
                'opportunity_score' => 0,
            ]);
            return;
        }

        // Primary = highest volume (if available), otherwise highest opportunity score
        $primary = $keywords->sortByDesc(fn (KiKeyword $k) => (($k->search_volume ?? 0) * 1000) + ($k->opportunity_score ?? 0))->first();

        $vols = $keywords->pluck('search_volume')->filter(fn ($v) => $v !== null);
        $kds = $keywords->pluck('keyword_difficulty')->filter(fn ($v) => $v !== null);
        $opps = $keywords->pluck('opportunity_score')->filter(fn ($v) => $v !== null);

        $supporting = $keywords
            ->filter(fn (KiKeyword $k) => $k->id !== $primary->id)
            ->take(6)
            ->map(fn (KiKeyword $k) => [
                'keyword' => $k->keyword,
                'suggested_format' => $k->question_category ? 'FAQ / Sub-section' : ($k->is_long_tail ? 'Supporting Article' : 'H2 Section'),
                'search_intent' => $k->search_intent,
            ])
            ->values()
            ->toArray();

        $cluster->update([
            'primary_keyword' => $primary->keyword,
            'keyword_count' => $keywords->count(),
            'combined_search_volume' => $vols->isNotEmpty() ? (int) $vols->sum() : null,
            'avg_difficulty' => $kds->isNotEmpty() ? round((float) $kds->avg(), 2) : null,
            'opportunity_score' => $opps->isNotEmpty() ? round((float) $opps->avg(), 2) : 0,
            'suggested_pillar_page' => $cluster->suggested_pillar_page ?: $this->suggestPillarTitle($primary->keyword, $cluster->search_intent),
            'suggested_supporting_content' => $supporting,
        ]);

        // Ensure primary flag is accurate
        foreach ($members as $m) {
            $shouldBePrimary = $m->keyword_id === $primary->id;
            if ((bool) $m->is_primary !== $shouldBePrimary) {
                $m->update(['is_primary' => $shouldBePrimary]);
            }
        }
    }

    /**
     * Semantic grouping with strict search-intent partitioning so conflicting intents never mix.
     */
    private function groupSemantically(Collection $keywords): array
    {
        $byIntent = $keywords->groupBy(fn (KiKeyword $k) => $k->search_intent ?: 'informational');
        $groups = [];

        foreach ($byIntent as $intent => $bucket) {
            $sorted = $bucket->sortByDesc(fn (KiKeyword $k) => (($k->search_volume ?? 0) * 100) + ($k->opportunity_score ?? 0))->values();
            $localClusters = [];

            foreach ($sorted as $kw) {
                $tokens = $this->meaningfulTokens($kw->keyword);
                $bestIdx = null;
                $bestScore = 0.0;

                foreach ($localClusters as $idx => $lc) {
                    $sim = $this->jaccard($tokens, $lc['seed_tokens']);
                    // Also check shared bigram / root containment
                    if ($sim < 0.35 && $this->sharesRootPhrase($kw->normalized_keyword, $lc['seed_norm'])) {
                        $sim = 0.50;
                    }
                    if ($sim >= 0.35 && $sim > $bestScore) {
                        $bestScore = $sim;
                        $bestIdx = $idx;
                    }
                }

                if ($bestIdx !== null) {
                    $localClusters[$bestIdx]['members'][] = $kw;
                    $localClusters[$bestIdx]['similarities'][$kw->id] = round($bestScore, 2);
                } else {
                    $localClusters[] = [
                        'intent' => $intent,
                        'seed_tokens' => $tokens,
                        'seed_norm' => $kw->normalized_keyword,
                        'members' => [$kw],
                        'similarities' => [$kw->id => 1.0],
                    ];
                }
            }

            $groups = array_merge($groups, $localClusters);
        }

        return $groups;
    }

    /**
     * SERP-based clustering: groups keywords sharing >= 2 Top-10 ranking URLs (within the same intent),
     * falling back to semantic grouping for keywords without SERP URLs.
     */
    private function groupBySerpAndSemantic(Collection $keywords): array
    {
        $byIntent = $keywords->groupBy(fn (KiKeyword $k) => $k->search_intent ?: 'informational');
        $groups = [];

        foreach ($byIntent as $intent => $bucket) {
            $withSerp = [];
            $withoutSerp = collect();

            foreach ($bucket as $kw) {
                $urls = $this->extractNormalizedSerpUrls($kw);
                if (count($urls) >= 3) {
                    $withSerp[] = ['kw' => $kw, 'urls' => $urls];
                } else {
                    $withoutSerp->push($kw);
                }
            }

            $serpGroups = [];
            foreach ($withSerp as $entry) {
                $kw = $entry['kw'];
                $urls = $entry['urls'];
                $bestIdx = null;
                $bestOverlap = 0;

                foreach ($serpGroups as $idx => $sg) {
                    $shared = count(array_intersect($urls, $sg['seed_urls']));
                    if ($shared >= 2 && $shared > $bestOverlap) {
                        $bestOverlap = $shared;
                        $bestIdx = $idx;
                    }
                }

                if ($bestIdx !== null) {
                    $serpGroups[$bestIdx]['members'][] = $kw;
                    $serpGroups[$bestIdx]['similarities'][$kw->id] = round(min(1.0, $bestOverlap / 10), 2);
                } else {
                    $serpGroups[] = [
                        'intent' => $intent,
                        'seed_urls' => $urls,
                        'members' => [$kw],
                        'similarities' => [$kw->id => 1.0],
                    ];
                }
            }

            $groups = array_merge($groups, $serpGroups);

            if ($withoutSerp->isNotEmpty()) {
                $groups = array_merge($groups, $this->groupSemantically($withoutSerp));
            }
        }

        return $groups;
    }

    private function persistClusterFromGroup(Website $website, string $method, array $members, array $similarities): KiCluster
    {
        $collection = collect($members);
        $primary = $collection->sortByDesc(fn (KiKeyword $k) => (($k->search_volume ?? 0) * 1000) + ($k->opportunity_score ?? 0))->first();

        $cluster = KiCluster::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'name' => mb_substr(ucwords($primary->keyword), 0, 175),
            'primary_keyword' => mb_substr($primary->keyword, 0, 250),
            'clustering_method' => $method,
            'search_intent' => $primary->search_intent ?: 'informational',
            'suggested_pillar_page' => mb_substr($this->suggestPillarTitle($primary->keyword, $primary->search_intent), 0, 250),
            'is_saved' => false,
        ]);

        foreach ($collection as $kw) {
            KiClusterMembership::firstOrCreate(
                ['cluster_id' => $cluster->id, 'keyword_id' => $kw->id],
                [
                    'is_primary' => $kw->id === $primary->id,
                    'similarity_score' => $similarities[$kw->id] ?? 0.75,
                ]
            );
        }

        $this->recalculateClusterAggregates($cluster);
        return $cluster;
    }

    private function meaningfulTokens(string $text): array
    {
        $raw = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($raw as $t) {
            if (mb_strlen($t) < 2 || in_array($t, self::STOP_WORDS, true)) {
                continue;
            }
            // Lightweight singularization stemmer
            if (mb_strlen($t) > 4 && str_ends_with($t, 'ies')) {
                $t = substr($t, 0, -3) . 'y';
            } elseif (mb_strlen($t) > 4 && str_ends_with($t, 's') && !str_ends_with($t, 'ss')) {
                $t = substr($t, 0, -1);
            }
            $tokens[] = $t;
        }
        return array_values(array_unique($tokens));
    }

    private function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union > 0 ? $inter / $union : 0.0;
    }

    private function sharesRootPhrase(string $a, string $b): bool
    {
        if (mb_strlen($a) < 5 || mb_strlen($b) < 5) {
            return false;
        }
        return str_contains($a, $b) || str_contains($b, $a);
    }

    private function extractNormalizedSerpUrls(KiKeyword $kw): array
    {
        if (!is_array($kw->serp_top_urls)) {
            return [];
        }
        $urls = [];
        foreach ($kw->serp_top_urls as $item) {
            $u = is_array($item) ? ($item['url'] ?? '') : (string) $item;
            $norm = rtrim(strtolower(trim((string) $u)), '/');
            if ($norm !== '') {
                $urls[] = $norm;
            }
        }
        return array_values(array_unique($urls));
    }

    private function suggestPillarTitle(string $primaryKeyword, ?string $intent): string
    {
        $clean = ucwords(trim($primaryKeyword));
        return match ($intent) {
            'transactional' => "{$clean}: Services, Pricing & Booking Guide",
            'commercial' => "Best {$clean} Options Compared (Buyer's Guide)",
            'navigational' => "{$clean} Official Overview & Resources",
            default => "Complete Guide to {$clean}",
        };
    }
}
