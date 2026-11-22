<?php

namespace App\Domain\Competitors\Services;

use App\Domain\Competitors\CompetitorProviderManager;
use App\Models\CompetitorBacklink;
use App\Models\CompetitorDomain;
use App\Models\Website;
use Illuminate\Support\Collection;

class BacklinkGapService
{
    public function __construct(private CompetitorProviderManager $providerManager)
    {
    }

    /**
     * Sync provider-backed backlink datasets for target website & tracked competitors.
     * Never fabricates or scrapes private backlink databases.
     */
    public function syncBacklinks(Website $website, ?array $competitorIds = null, bool $forceRefresh = false): array
    {
        $provider = $this->providerManager->resolveForCapability('backlinks');
        if ($provider->getName() === 'first_party_crawl') {
            return [
                'available' => false,
                'provider' => 'unconfigured',
                'synced_rows' => 0,
                'message' => 'No backlink provider is connected. Add DATAFORSEO_LOGIN/PASSWORD, AHREFS_API_KEY, or SEMRUSH_API_KEY in server environment variables to enable Backlink Gap Analysis.',
            ];
        }

        $targetDomain = CompetitorDomain::normalizeDomain($website->domain);
        $compQuery = CompetitorDomain::where('website_id', $website->id)->where('is_active', true);
        if (!empty($competitorIds)) {
            $compQuery->whereIn('id', $competitorIds);
        }
        /** @var Collection<int, CompetitorDomain> $competitors */
        $competitors = $compQuery->limit(5)->get();

        $totalRows = 0;

        // 1. Target domain backlinks
        $targetRes = $this->providerManager->cachedCall(
            $website,
            'backlinks',
            "{$provider->getName()}:bl:{$targetDomain}",
            "backlinks:{$targetDomain}",
            fn () => $provider->fetchBacklinks($targetDomain, 60),
            $forceRefresh
        );
        if ($targetRes['available'] ?? false) {
            $totalRows += $this->storeBacklinkRows($website, null, $targetDomain, true, $provider->getName(), $targetRes['backlinks'] ?? []);
        }

        // 2. Competitor backlinks
        foreach ($competitors as $comp) {
            $res = $this->providerManager->cachedCall(
                $website,
                'backlinks',
                "{$provider->getName()}:bl:{$comp->domain}",
                "backlinks:{$comp->domain}",
                fn () => $provider->fetchBacklinks($comp->domain, 60),
                $forceRefresh
            );
            if ($res['available'] ?? false) {
                $totalRows += $this->storeBacklinkRows($website, $comp, $comp->domain, false, $provider->getName(), $res['backlinks'] ?? []);
            }
        }

        return [
            'available' => true,
            'provider' => $provider->getName(),
            'synced_rows' => $totalRows,
            'competitors_analyzed' => $competitors->count(),
        ];
    }

    /**
     * Build Backlink Gap view: domains linking to competitors but NOT target, shared domains, follow/nofollow breakdown.
     */
    public function getBacklinkGapReport(Website $website, array $filters = []): array
    {
        $targetRefDomains = CompetitorBacklink::where('website_id', $website->id)
            ->where('is_target_site', true)
            ->pluck('referring_domain')
            ->map(fn ($d) => strtolower($d))
            ->unique()
            ->values()
            ->toArray();

        $allCompLinks = CompetitorBacklink::where('website_id', $website->id)
            ->where('is_target_site', false)
            ->get();

        $allTargetLinks = CompetitorBacklink::where('website_id', $website->id)
            ->where('is_target_site', true)
            ->get();

        $dofollowCount = $allCompLinks->where('link_type', 'dofollow')->count();
        $nofollowCount = $allCompLinks->where('link_type', 'nofollow')->count();
        $newCount = $allCompLinks->where('link_status', 'new')->count();
        $lostCount = $allCompLinks->where('link_status', 'lost')->count();

        // Group competitor backlinks by referring_domain to compute true outreach gaps
        $grouped = $allCompLinks->groupBy(fn (CompetitorBacklink $b) => strtolower($b->referring_domain));
        $gapOpportunities = [];
        $sharedDomains = [];

        foreach ($grouped as $refDomain => $rows) {
            $linksToTarget = in_array($refDomain, $targetRefDomains, true);
            $competitorsLinked = $rows->pluck('target_domain')->unique()->values()->toArray();
            $topRow = $rows->sortByDesc('referring_authority')->first();

            $entry = [
                'referring_domain' => $refDomain,
                'referring_authority' => $topRow?->referring_authority,
                'competitors_linked' => $competitorsLinked,
                'competitors_count' => count($competitorsLinked),
                'backlinks_count' => $rows->count(),
                'link_type' => $topRow?->link_type ?? 'unknown',
                'sample_anchor' => $topRow?->anchor_text,
                'sample_referring_url' => $topRow?->referring_url,
                'sample_destination_url' => $topRow?->destination_url,
                'link_status' => $topRow?->link_status ?? 'active',
                'first_seen_at' => $topRow?->first_seen_at?->toIso8601String(),
                'data_source' => $topRow?->data_source ?? 'unknown',
                'links_to_target' => $linksToTarget,
            ];

            if ($linksToTarget) {
                $sharedDomains[] = $entry;
            } else {
                $gapOpportunities[] = $entry;
            }
        }

        usort($gapOpportunities, fn ($a, $b) => ($b['competitors_count'] <=> $a['competitors_count']) ?: (($b['referring_authority'] ?? 0) <=> ($a['referring_authority'] ?? 0)));
        usort($sharedDomains, fn ($a, $b) => ($b['referring_authority'] ?? 0) <=> ($a['referring_authority'] ?? 0));

        // Filter raw rows if requested
        $mode = $filters['mode'] ?? 'gap'; // gap | shared | all_links
        $linkType = $filters['link_type'] ?? null;
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $list = $mode === 'shared' ? $sharedDomains : $gapOpportunities;
        if ($linkType && $linkType !== 'all') {
            $list = array_values(array_filter($list, fn ($r) => ($r['link_type'] ?? '') === $linkType));
        }
        if ($search !== '') {
            $list = array_values(array_filter($list, fn ($r) => str_contains(strtolower($r['referring_domain']), $search) || str_contains(strtolower((string) ($r['sample_anchor'] ?? '')), $search)));
        }

        return [
            'provider_connected' => $this->providerManager->hasExternalProviderFor('backlinks'),
            'active_provider' => $this->providerManager->resolveForCapability('backlinks')->getName(),
            'summary' => [
                'target_referring_domains' => count($targetRefDomains),
                'target_backlinks_recorded' => $allTargetLinks->count(),
                'competitor_referring_domains' => $grouped->count(),
                'competitor_backlinks_recorded' => $allCompLinks->count(),
                'gap_domains_count' => count($gapOpportunities),
                'shared_domains_count' => count($sharedDomains),
                'dofollow_count' => $dofollowCount,
                'nofollow_count' => $nofollowCount,
                'new_backlinks_count' => $newCount,
                'lost_backlinks_count' => $lostCount,
            ],
            'items' => $list,
            'raw_links' => $allCompLinks->take(100)->values(),
        ];
    }

    private function storeBacklinkRows(
        Website $website,
        ?CompetitorDomain $competitor,
        string $targetDomain,
        bool $isTarget,
        string $provider,
        array $rows
    ): int {
        if (empty($rows)) {
            return 0;
        }

        // Replace previous snapshot rows for this target domain so duplicates do not accumulate
        CompetitorBacklink::where('website_id', $website->id)
            ->where('target_domain', $targetDomain)
            ->delete();

        $count = 0;
        foreach ($rows as $row) {
            $refDomain = strtolower(trim((string) ($row['referring_domain'] ?? '')));
            if ($refDomain === '') {
                continue;
            }
            CompetitorBacklink::create([
                'organization_id' => $website->organization_id,
                'website_id' => $website->id,
                'competitor_domain_id' => $competitor?->id,
                'target_domain' => $targetDomain,
                'is_target_site' => $isTarget,
                'referring_domain' => $refDomain,
                'referring_url' => $row['referring_url'] ?? null,
                'destination_url' => $row['destination_url'] ?? null,
                'anchor_text' => isset($row['anchor_text']) ? substr((string) $row['anchor_text'], 0, 500) : null,
                'link_type' => $row['link_type'] ?? 'unknown',
                'referring_authority' => $row['referring_authority'] ?? null,
                'link_status' => $row['link_status'] ?? 'active',
                'first_seen_at' => $row['first_seen_at'] ?? null,
                'last_seen_at' => $row['last_seen_at'] ?? now(),
                'data_source' => $provider,
            ]);
            $count++;
        }

        return $count;
    }
}
