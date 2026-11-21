<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Models\AiUsageLog;
use App\Models\ContentArticle;
use App\Models\CrawledPage;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Website;
use Carbon\Carbon;

class QuotaEnforcementService
{
    /**
     * Check if organization can add a new website.
     * Throws QuotaExceededException if exceeded.
     */
    public function assertCanAddWebsite(Organization $org): void
    {
        $plan = $org->getEffectivePlan();
        $limit = $plan['max_websites'] ?? 3;
        $current = Website::where('organization_id', $org->id)->where('is_archived', false)->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                "Website limit of {$limit} reached for your {$plan['name']}. Please upgrade to add more websites.",
                'max_websites',
                $current,
                $limit
            );
        }
    }

    /**
     * Check if organization can crawl requested number of pages.
     */
    public function assertCanCrawlPages(Organization $org, int $pagesRequested): void
    {
        $plan = $org->getEffectivePlan();
        $limit = $plan['max_crawl_pages'] ?? 250;

        if ($pagesRequested > $limit) {
            throw new QuotaExceededException(
                "Requested crawl size ({$pagesRequested} pages) exceeds your {$plan['name']} limit of {$limit} pages per crawl.",
                'max_crawl_pages',
                $pagesRequested,
                $limit
            );
        }
    }

    /**
     * Check if organization can consume AI tokens.
     */
    public function assertCanConsumeAiTokens(Organization $org, int $tokensRequested): void
    {
        $plan = $org->getEffectivePlan();
        $limit = $plan['max_ai_tokens_monthly'] ?? 50_000;

        $startOfMonth = Carbon::now()->startOfMonth();
        $usedThisMonth = (int) AiUsageLog::where('organization_id', $org->id)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('tokens_total');

        if (($usedThisMonth + $tokensRequested) > $limit) {
            throw new QuotaExceededException(
                "Monthly AI token limit of " . number_format($limit) . " reached for your {$plan['name']}.",
                'max_ai_tokens_monthly',
                $usedThisMonth,
                $limit
            );
        }
    }

    /**
     * Check if organization can create another content article this month.
     */
    public function assertCanCreateArticle(Organization $org): void
    {
        $plan = $org->getEffectivePlan();
        $limit = $plan['max_articles_monthly'] ?? 10;

        $startOfMonth = Carbon::now()->startOfMonth();
        $articlesThisMonth = ContentArticle::whereHas('website', function ($q) use ($org) {
            $q->where('organization_id', $org->id);
        })->where('created_at', '>=', $startOfMonth)->count();

        if ($articlesThisMonth >= $limit) {
            throw new QuotaExceededException(
                "Monthly content article limit of {$limit} reached for your {$plan['name']}.",
                'max_articles_monthly',
                $articlesThisMonth,
                $limit
            );
        }
    }

    /**
     * Check if organization can invite another team member.
     */
    public function assertCanAddTeamMember(Organization $org): void
    {
        $plan = $org->getEffectivePlan();
        $limit = $plan['max_team_seats'] ?? 2;
        $current = OrganizationMember::where('organization_id', $org->id)->count();

        if ($current >= $limit) {
            throw new QuotaExceededException(
                "Team seat limit of {$limit} reached for your {$plan['name']}. Please upgrade to invite additional team members.",
                'max_team_seats',
                $current,
                $limit
            );
        }
    }

    /**
     * Check if organization is permitted to execute live CMS writes.
     */
    public function assertCanUseCmsWrite(Organization $org): void
    {
        $plan = $org->getEffectivePlan();
        $supported = (bool) ($plan['supports_cms_write'] ?? false);

        if (!$supported) {
            throw new QuotaExceededException(
                "Live CMS write execution is not included in the {$plan['name']}. Please upgrade to Agency Pro or Enterprise.",
                'supports_cms_write',
                0,
                1
            );
        }
    }

    /**
     * Check if organization is permitted to export white-label reports.
     */
    public function assertCanExportWhiteLabel(Organization $org): void
    {
        $plan = $org->getEffectivePlan();
        $supported = (bool) ($plan['supports_white_label'] ?? false);

        if (!$supported) {
            throw new QuotaExceededException(
                "White-label branded reports are not included in the {$plan['name']}. Please upgrade to Agency Pro or Enterprise.",
                'supports_white_label',
                0,
                1
            );
        }
    }

    /**
     * Compile complete usage summary vs quotas.
     */
    public function getUsageSummary(Organization $org): array
    {
        $plan = $org->getEffectivePlan();
        $startOfMonth = Carbon::now()->startOfMonth();

        // 1. Websites count
        $websitesCount = Website::where('organization_id', $org->id)->where('is_archived', false)->count();

        // 2. Crawled pages this month
        $crawledPagesThisMonth = CrawledPage::whereHas('crawlJob.website', function ($q) use ($org) {
            $q->where('organization_id', $org->id);
        })->where('crawled_at', '>=', $startOfMonth)->count();

        // 3. AI Tokens used this month
        $tokensUsedThisMonth = (int) AiUsageLog::where('organization_id', $org->id)
            ->where('created_at', '>=', $startOfMonth)
            ->sum('tokens_total');

        // 4. Articles generated this month
        $articlesThisMonth = ContentArticle::whereHas('website', function ($q) use ($org) {
            $q->where('organization_id', $org->id);
        })->where('created_at', '>=', $startOfMonth)->count();

        // 5. Team seats
        $membersCount = OrganizationMember::where('organization_id', $org->id)->count();

        // 6. Subscription details
        $subscription = $org->subscription;

        return [
            'organization_id' => $org->id,
            'organization_name' => $org->name,
            'plan' => [
                'id' => $plan['id'] ?? 'starter',
                'name' => $plan['name'] ?? 'Starter Plan',
                'description' => $plan['description'] ?? '',
                'price_monthly' => $plan['price_monthly'] ?? 0,
                'price_yearly' => $plan['price_yearly'] ?? 0,
            ],
            'subscription' => [
                'status' => $subscription?->status ?? 'active',
                'billing_cycle' => $subscription?->billing_cycle ?? 'monthly',
                'current_period_start' => $subscription?->current_period_start?->toIso8601String() ?? $startOfMonth->toIso8601String(),
                'current_period_end' => $subscription?->current_period_end?->toIso8601String() ?? Carbon::now()->endOfMonth()->toIso8601String(),
                'cancel_at_period_end' => $subscription?->cancel_at_period_end ?? false,
                'card_brand' => $subscription?->card_brand ?? 'visa',
                'card_last_four' => $subscription?->card_last_four ?? '4242',
            ],
            'quotas' => [
                'websites' => [
                    'used' => $websitesCount,
                    'limit' => $plan['max_websites'] ?? 3,
                    'remaining' => max(0, ($plan['max_websites'] ?? 3) - $websitesCount),
                    'percentage' => min(100, round(($websitesCount / max(1, $plan['max_websites'] ?? 3)) * 100)),
                ],
                'crawl_pages' => [
                    'used' => $crawledPagesThisMonth,
                    'limit' => $plan['max_crawl_pages'] ?? 250,
                    'remaining' => max(0, ($plan['max_crawl_pages'] ?? 250) - $crawledPagesThisMonth),
                    'percentage' => min(100, round(($crawledPagesThisMonth / max(1, $plan['max_crawl_pages'] ?? 250)) * 100)),
                ],
                'ai_tokens' => [
                    'used' => $tokensUsedThisMonth,
                    'limit' => $plan['max_ai_tokens_monthly'] ?? 50_000,
                    'remaining' => max(0, ($plan['max_ai_tokens_monthly'] ?? 50_000) - $tokensUsedThisMonth),
                    'percentage' => min(100, round(($tokensUsedThisMonth / max(1, $plan['max_ai_tokens_monthly'] ?? 50_000)) * 100)),
                ],
                'articles' => [
                    'used' => $articlesThisMonth,
                    'limit' => $plan['max_articles_monthly'] ?? 10,
                    'remaining' => max(0, ($plan['max_articles_monthly'] ?? 10) - $articlesThisMonth),
                    'percentage' => min(100, round(($articlesThisMonth / max(1, $plan['max_articles_monthly'] ?? 10)) * 100)),
                ],
                'team_seats' => [
                    'used' => $membersCount,
                    'limit' => $plan['max_team_seats'] ?? 2,
                    'remaining' => max(0, ($plan['max_team_seats'] ?? 2) - $membersCount),
                    'percentage' => min(100, round(($membersCount / max(1, $plan['max_team_seats'] ?? 2)) * 100)),
                ],
            ],
            'features' => [
                'supports_cms_write' => (bool) ($plan['supports_cms_write'] ?? false),
                'supports_white_label' => (bool) ($plan['supports_white_label'] ?? false),
                'supports_webhooks' => (bool) ($plan['supports_webhooks'] ?? false),
                'crawl_frequency' => $plan['crawl_frequency'] ?? 'weekly',
            ],
        ];
    }
}
