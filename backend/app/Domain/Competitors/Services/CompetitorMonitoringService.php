<?php

namespace App\Domain\Competitors\Services;

use App\Models\CompetitorDomain;
use App\Models\CompetitorDomainSnapshot;
use App\Models\CompetitorMonitoringConfig;
use App\Models\Website;
use Carbon\Carbon;

class CompetitorMonitoringService
{
    public function __construct(private DomainComparisonService $comparisonService)
    {
    }

    public function getOrCreateConfig(Website $website): CompetitorMonitoringConfig
    {
        return CompetitorMonitoringConfig::firstOrCreate(
            ['website_id' => $website->id],
            [
                'organization_id' => $website->organization_id,
                'frequency' => CompetitorMonitoringConfig::FREQ_WEEKLY,
                'country' => $website->target_country ?: 'US',
                'language' => $website->preferred_language ?: 'en',
                'notify_on_rank_change' => true,
                'notify_on_new_keywords' => true,
                'notify_on_backlink_change' => true,
                'min_position_delta' => 3,
                'next_run_at' => now()->addWeek(),
                'recent_alerts' => [],
            ]
        );
    }

    public function updateConfig(Website $website, array $data): CompetitorMonitoringConfig
    {
        $config = $this->getOrCreateConfig($website);
        $frequency = $data['frequency'] ?? $config->frequency;

        $nextRun = match ($frequency) {
            CompetitorMonitoringConfig::FREQ_DAILY => now()->addDay(),
            CompetitorMonitoringConfig::FREQ_WEEKLY => now()->addWeek(),
            CompetitorMonitoringConfig::FREQ_MONTHLY => now()->addMonth(),
            default => null,
        };

        $config->update([
            'frequency' => $frequency,
            'country' => strtoupper((string) ($data['country'] ?? $config->country)),
            'language' => strtolower((string) ($data['language'] ?? $config->language)),
            'notify_on_rank_change' => array_key_exists('notify_on_rank_change', $data) ? (bool) $data['notify_on_rank_change'] : $config->notify_on_rank_change,
            'notify_on_new_keywords' => array_key_exists('notify_on_new_keywords', $data) ? (bool) $data['notify_on_new_keywords'] : $config->notify_on_new_keywords,
            'notify_on_backlink_change' => array_key_exists('notify_on_backlink_change', $data) ? (bool) $data['notify_on_backlink_change'] : $config->notify_on_backlink_change,
            'min_position_delta' => (int) ($data['min_position_delta'] ?? $config->min_position_delta),
            'next_run_at' => $nextRun,
        ]);

        return $config->fresh();
    }

    /**
     * Run a monitoring snapshot pass and emit alerts ONLY when meaningful deltas are detected.
     */
    public function runMonitoringCheck(Website $website, bool $forceRefresh = false): array
    {
        $config = $this->getOrCreateConfig($website);
        $competitors = CompetitorDomain::where('website_id', $website->id)
            ->where('is_active', true)
            ->limit(5)
            ->get();

        $newAlerts = [];

        // Capture target + each competitor snapshot and compare with previous snapshot
        $domainsToCheck = [
            ['domain' => CompetitorDomain::normalizeDomain($website->domain), 'is_target' => true, 'competitor' => null],
        ];
        foreach ($competitors as $c) {
            $domainsToCheck[] = ['domain' => $c->domain, 'is_target' => false, 'competitor' => $c];
        }

        foreach ($domainsToCheck as $entry) {
            $prev = CompetitorDomainSnapshot::where('website_id', $website->id)
                ->where('domain', $entry['domain'])
                ->orderByDesc('captured_at')
                ->first();

            $current = $this->comparisonService->captureDomainSnapshot(
                $website,
                $entry['domain'],
                $entry['is_target'],
                $entry['competitor'],
                $config->country,
                $config->language,
                $forceRefresh
            );

            if ($prev) {
                // Detect meaningful keyword count shift (>= 5%)
                if ($config->notify_on_new_keywords && $prev->organic_keywords_count !== null && $current->organic_keywords_count !== null) {
                    $diff = $current->organic_keywords_count - $prev->organic_keywords_count;
                    if (abs($diff) >= max(5, (int) round($prev->organic_keywords_count * 0.05))) {
                        $newAlerts[] = [
                            'type' => $diff > 0 ? 'keyword_growth' : 'keyword_drop',
                            'domain' => $entry['domain'],
                            'is_target' => $entry['is_target'],
                            'message' => "{$entry['domain']} organic keyword count changed by " . ($diff > 0 ? "+{$diff}" : (string) $diff) . " (from {$prev->organic_keywords_count} to {$current->organic_keywords_count}).",
                            'detected_at' => now()->toIso8601String(),
                        ];
                    }
                }

                // Detect meaningful referring domain change
                if ($config->notify_on_backlink_change && $prev->referring_domains_count !== null && $current->referring_domains_count !== null) {
                    $blDiff = $current->referring_domains_count - $prev->referring_domains_count;
                    if (abs($blDiff) >= 3) {
                        $newAlerts[] = [
                            'type' => $blDiff > 0 ? 'backlink_gain' : 'backlink_loss',
                            'domain' => $entry['domain'],
                            'is_target' => $entry['is_target'],
                            'message' => "{$entry['domain']} referring domains changed by " . ($blDiff > 0 ? "+{$blDiff}" : (string) $blDiff) . " ({$prev->referring_domains_count} → {$current->referring_domains_count}).",
                            'detected_at' => now()->toIso8601String(),
                        ];
                    }
                }

                // Detect first-party homepage title change (always works without paid APIs!)
                $prevTitle = $prev->crawled_signals['page_title'] ?? null;
                $currTitle = $current->crawled_signals['page_title'] ?? null;
                if ($prevTitle && $currTitle && $prevTitle !== $currTitle) {
                    $newAlerts[] = [
                        'type' => 'content_change',
                        'domain' => $entry['domain'],
                        'is_target' => $entry['is_target'],
                        'message' => "{$entry['domain']} updated homepage <title>: \"{$prevTitle}\" → \"{$currTitle}\".",
                        'detected_at' => now()->toIso8601String(),
                    ];
                }
            }
        }

        $mergedAlerts = array_slice(array_merge($newAlerts, $config->recent_alerts ?? []), 0, 30);
        $nextRun = match ($config->frequency) {
            CompetitorMonitoringConfig::FREQ_DAILY => now()->addDay(),
            CompetitorMonitoringConfig::FREQ_WEEKLY => now()->addWeek(),
            CompetitorMonitoringConfig::FREQ_MONTHLY => now()->addMonth(),
            default => null,
        };

        $config->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRun,
            'recent_alerts' => $mergedAlerts,
        ]);

        return [
            'config' => $config->fresh(),
            'domains_checked' => count($domainsToCheck),
            'new_alerts_count' => count($newAlerts),
            'new_alerts' => $newAlerts,
        ];
    }

    /**
     * Build historical snapshot timeline series grouped by domain for charting over time.
     */
    public function getHistoricalTimeline(Website $website): array
    {
        $snapshots = CompetitorDomainSnapshot::where('website_id', $website->id)
            ->orderBy('captured_at')
            ->limit(120)
            ->get();

        $byDomain = [];
        foreach ($snapshots as $snap) {
            $byDomain[$snap->domain][] = [
                'id' => $snap->id,
                'captured_at' => $snap->captured_at->toIso8601String(),
                'date' => Carbon::parse($snap->captured_at)->toDateString(),
                'is_target' => $snap->is_target,
                'organic_traffic_estimate' => $snap->organic_traffic_estimate,
                'organic_keywords_count' => $snap->organic_keywords_count,
                'referring_domains_count' => $snap->referring_domains_count,
                'authority_score' => $snap->authority_score,
                'serp_visibility_score' => $snap->serp_visibility_score,
                'indexed_pages_count' => $snap->indexed_pages_count,
                'word_count' => $snap->crawled_signals['word_count'] ?? null,
                'data_source' => $snap->data_source,
                'data_classification' => $snap->data_classification,
            ];
        }

        return [
            'total_snapshots' => $snapshots->count(),
            'series_by_domain' => $byDomain,
            'recent_snapshots' => $snapshots->sortByDesc('captured_at')->take(25)->values(),
        ];
    }
}
