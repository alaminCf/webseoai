<?php

namespace App\Domain\RankChecker;

use App\Domain\Competitors\Providers\SerperProvider;
use App\Models\GlobalSerpCache;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\SerpCreditUsage;
use App\Models\Website;
use Carbon\Carbon;
use Throwable;

class SerpCreditService
{
    /**
     * Compute real-time daily & monthly SERP credit usage and quota status for an organization.
     */
    public function getQuotaStatus(Organization $org): array
    {
        $hasOwnKey = SerperProvider::resolveWorkspaceIntegration($org->id) !== null;
        $plan = (string) ($org->subscription?->plan_id ?? $org->billing_plan ?? 'agency_pro');
        $isInternal = $plan === 'agency_internal';

        $planQuotas = PlatformSetting::getValue('serp_quotas');
        $planLimits = $planQuotas[$plan] ?? $planQuotas['starter'] ?? ['daily' => 10, 'monthly' => 50];

        // Allow Super Admin to assign per-workspace custom quota overrides
        $override = is_array($org->settings['serp_quota_override'] ?? null)
            ? $org->settings['serp_quota_override']
            : [];

        $dailyLimit = isset($override['daily']) && is_numeric($override['daily'])
            ? max(0, (int) $override['daily'])
            : (int) ($planLimits['daily'] ?? 10);
        $monthlyLimit = isset($override['monthly']) && is_numeric($override['monthly'])
            ? max(0, (int) $override['monthly'])
            : (int) ($planLimits['monthly'] ?? 50);

        $todayStart = now()->startOfDay();
        $monthStart = now()->startOfMonth();

        // Only live platform-funded searches count against platform quota (BYOK & cache hits cost 0!)
        $dailyUsed = (int) SerpCreditUsage::where('organization_id', $org->id)
            ->where('cache_hit', false)
            ->where('key_source', '!=', 'workspace_byok')
            ->where('created_at', '>=', $todayStart)
            ->sum('credits_used');

        $monthlyUsed = (int) SerpCreditUsage::where('organization_id', $org->id)
            ->where('cache_hit', false)
            ->where('key_source', '!=', 'workspace_byok')
            ->where('created_at', '>=', $monthStart)
            ->sum('credits_used');

        $cacheHitsMonth = (int) SerpCreditUsage::where('organization_id', $org->id)
            ->where('cache_hit', true)
            ->where('created_at', '>=', $monthStart)
            ->count();

        $unlimited = $hasOwnKey || $isInternal;
        $dailyExceeded = !$unlimited && $dailyLimit > 0 && $dailyUsed >= $dailyLimit;
        $monthlyExceeded = !$unlimited && $monthlyLimit > 0 && $monthlyUsed >= $monthlyLimit;
        $exceeded = $dailyExceeded || $monthlyExceeded;
        $exceededReason = $monthlyExceeded ? 'monthly' : ($dailyExceeded ? 'daily' : null);

        return [
            'plan' => $plan,
            'is_byok' => $hasOwnKey,
            'is_unlimited' => $unlimited,
            'daily_used' => $dailyUsed,
            'daily_limit' => $dailyLimit,
            'daily_remaining' => $unlimited ? null : max(0, $dailyLimit - $dailyUsed),
            'monthly_used' => $monthlyUsed,
            'monthly_limit' => $monthlyLimit,
            'monthly_remaining' => $unlimited ? null : max(0, $monthlyLimit - $monthlyUsed),
            'cache_hits_month' => $cacheHitsMonth,
            'exceeded' => $exceeded,
            'exceeded_reason' => $exceededReason,
            'daily_resets_at' => now()->endOfDay()->toIso8601String(),
            'monthly_resets_at' => now()->endOfMonth()->toIso8601String(),
        ];
    }

    /**
     * Check Smart Cross-User Global SERP Cache before spending any API credits.
     * Any cached entry with depth >= requested depth satisfies the query with 0 credits!
     */
    public function lookupGlobalCache(
        string $keyword,
        string $country,
        string $language,
        string $device,
        string $location,
        int $depth,
        ?string $targetDomain = null
    ): ?GlobalSerpCache {
        $cacheCfg = PlatformSetting::getValue('serp_cache');
        if (empty($cacheCfg['enabled']) || ((int) ($cacheCfg['ttl_hours'] ?? 12)) <= 0) {
            return null;
        }

        $normKw = strtolower(trim($keyword));
        $normCountry = strtoupper(trim($country));
        $normLang = strtolower(trim($language));
        $normDevice = strtolower(trim($device));
        $normLoc = strtolower(trim($location));
        $normTarget = $targetDomain ? preg_replace('/^www\./i', '', strtolower(trim($targetDomain))) : null;

        try {
            // Prefer an entry covering the full requested depth
            $entry = GlobalSerpCache::where('keyword', $normKw)
                ->where('country', $normCountry)
                ->where('language', $normLang)
                ->where('device', $normDevice)
                ->where('location', $normLoc)
                ->where('depth', '>=', $depth)
                ->where('expires_at', '>', now())
                ->orderByDesc('depth')
                ->orderByDesc('updated_at')
                ->first();

            // Smart partial-depth cache hit: if a shallower cached SERP (e.g. Page 1) already contains
            // the target domain, we already have the exact top position with 0 credits spent!
            if (!$entry && $normTarget) {
                $shallow = GlobalSerpCache::where('keyword', $normKw)
                    ->where('country', $normCountry)
                    ->where('language', $normLang)
                    ->where('device', $normDevice)
                    ->where('location', $normLoc)
                    ->where('expires_at', '>', now())
                    ->orderByDesc('depth')
                    ->orderByDesc('updated_at')
                    ->first();

                if ($shallow) {
                    $decoded = $shallow->getDecodedPayload();
                    foreach ($decoded['organic_results'] ?? [] as $row) {
                        $rowDomain = preg_replace('/^www\./i', '', strtolower(trim((string) ($row['domain'] ?? ''))));
                        if ($rowDomain !== '' && ($rowDomain === $normTarget || str_ends_with($rowDomain, '.' . $normTarget))) {
                            $entry = $shallow;
                            break;
                        }
                    }
                }
            }

            if (!$entry) {
                return null;
            }

            $estimatedSaved = max(1, (int) ceil($depth / 10));
            $entry->increment('hit_count', 1);
            $entry->increment('credits_saved', $estimatedSaved);

            return $entry->fresh();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Store a clean, tenant-agnostic Google SERP snapshot into the Smart Cross-User Global Cache.
     */
    public function storeGlobalCache(
        string $provider,
        string $keyword,
        string $country,
        string $language,
        string $device,
        string $location,
        int $depth,
        array $payload,
        int $pagesFetched = 1
    ): void {
        $cacheCfg = PlatformSetting::getValue('serp_cache');
        $ttlHours = (int) ($cacheCfg['ttl_hours'] ?? 12);
        if (empty($cacheCfg['enabled']) || $ttlHours <= 0) {
            return;
        }

        $normKw = strtolower(trim($keyword));
        $normCountry = strtoupper(trim($country));
        $normLang = strtolower(trim($language));
        $normDevice = strtolower(trim($device));
        $normLoc = strtolower(trim($location));
        $hash = hash('sha256', implode('|', [$provider, $normKw, $normCountry, $normLang, $normDevice, $normLoc, $depth]));

        try {
            GlobalSerpCache::updateOrCreate(
                ['cache_hash' => $hash],
                [
                    'provider' => $provider,
                    'keyword' => $normKw,
                    'country' => $normCountry,
                    'language' => $normLang,
                    'device' => $normDevice,
                    'location' => $normLoc,
                    'depth' => $depth,
                    'payload' => json_encode($payload),
                    'pages_fetched' => max(1, $pagesFetched),
                    'expires_at' => Carbon::now()->addHours($ttlHours),
                ]
            );
        } catch (Throwable) {
            // Never break rank check if cache write fails
        }
    }

    /**
     * Record SERP credit usage (or cache hit) in the usage ledger.
     */
    public function recordUsage(
        Website $website,
        ?int $userId,
        ?int $platformApiKeyId,
        string $provider,
        string $keyword,
        string $country,
        int $depth,
        int $creditsUsed,
        bool $cacheHit,
        string $keySource
    ): void {
        try {
            SerpCreditUsage::create([
                'organization_id' => $website->organization_id,
                'user_id' => $userId,
                'website_id' => $website->id,
                'platform_api_key_id' => $platformApiKeyId,
                'provider' => $provider,
                'keyword' => mb_substr(trim($keyword), 0, 255),
                'country' => strtoupper(substr(trim($country), 0, 4)),
                'depth' => $depth,
                'credits_used' => max(0, $creditsUsed),
                'cache_hit' => $cacheHit,
                'key_source' => $keySource,
            ]);
        } catch (Throwable) {
            // Non-blocking audit telemetry
        }
    }

    public function getDefaultDepth(): int
    {
        $cacheCfg = PlatformSetting::getValue('serp_cache');
        $d = (int) ($cacheCfg['default_depth'] ?? 30);
        return in_array($d, [20, 30, 50, 100], true) ? $d : 30;
    }

    public function getCacheTtlHours(): int
    {
        $cacheCfg = PlatformSetting::getValue('serp_cache');
        return empty($cacheCfg['enabled']) ? 0 : max(0, (int) ($cacheCfg['ttl_hours'] ?? 12));
    }
}
