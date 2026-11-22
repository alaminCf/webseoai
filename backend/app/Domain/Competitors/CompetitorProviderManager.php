<?php

namespace App\Domain\Competitors;

use App\Domain\Competitors\Contracts\CompetitorProviderInterface;
use App\Domain\Competitors\Providers\AhrefsProvider;
use App\Domain\Competitors\Providers\DataForSeoProvider;
use App\Domain\Competitors\Providers\FirstPartyCrawlProvider;
use App\Domain\Competitors\Providers\SemrushProvider;
use App\Domain\Competitors\Providers\SerpApiProvider;
use App\Domain\Competitors\Providers\SerperProvider;
use App\Models\CompetitorProviderLog;
use App\Models\Website;
use Illuminate\Support\Facades\Cache;

class CompetitorProviderManager
{
    /** @var array<string, CompetitorProviderInterface> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'dataforseo' => new DataForSeoProvider(),
            'semrush' => new SemrushProvider(),
            'ahrefs' => new AhrefsProvider(),
            'serpapi' => new SerpApiProvider(),
            'serper' => new SerperProvider(),
            'first_party_crawl' => new FirstPartyCrawlProvider(),
        ];
    }

    /**
     * Resolve the best configured provider for a given capability.
     */
    public function resolveForCapability(string $capability): CompetitorProviderInterface
    {
        $priorityOrder = match ($capability) {
            'serp' => ['dataforseo', 'serper', 'serpapi', 'first_party_crawl'],
            'backlinks' => ['dataforseo', 'ahrefs', 'semrush', 'first_party_crawl'],
            'keywords' => ['dataforseo', 'semrush', 'first_party_crawl'],
            'discovery' => ['dataforseo', 'semrush', 'serper', 'serpapi', 'first_party_crawl'],
            'domain_metrics' => ['dataforseo', 'semrush', 'ahrefs', 'first_party_crawl'],
            default => ['dataforseo', 'first_party_crawl'],
        };

        foreach ($priorityOrder as $name) {
            $provider = $this->providers[$name] ?? null;
            if ($provider && $provider->isConfigured() && $provider->supports($capability)) {
                return $provider;
            }
        }

        return $this->providers['first_party_crawl'];
    }

    public function getFirstPartyProvider(): FirstPartyCrawlProvider
    {
        /** @var FirstPartyCrawlProvider $p */
        $p = $this->providers['first_party_crawl'];
        return $p;
    }

    public function hasExternalProviderFor(string $capability): bool
    {
        $resolved = $this->resolveForCapability($capability);
        return $resolved->getName() !== 'first_party_crawl';
    }

    /**
     * Return safe status matrix for UI configuration banner (NEVER exposes secrets).
     */
    public function getStatusSummary(int $organizationId, ?int $websiteId = null): array
    {
        $providers = [];
        foreach (['dataforseo', 'serper', 'semrush', 'ahrefs', 'serpapi'] as $key) {
            $p = $this->providers[$key];
            $cfg = config("competitors.providers.{$key}", []);
            $providers[] = [
                'id' => $key,
                'label' => $p->getLabel(),
                'configured' => $p->isConfigured(),
                'capabilities' => $p->supportedCapabilities(),
                'required_env_vars' => match ($key) {
                    'dataforseo' => ['DATAFORSEO_LOGIN', 'DATAFORSEO_PASSWORD'],
                    'serper' => ['SERPER_API_KEY'],
                    'semrush' => ['SEMRUSH_API_KEY'],
                    'ahrefs' => ['AHREFS_API_KEY'],
                    'serpapi' => ['SERPAPI_KEY'],
                    default => [],
                },
                'docs_url' => $cfg['docs_url'] ?? null,
            ];
        }

        $capabilities = [];
        foreach (['discovery', 'domain_metrics', 'keywords', 'backlinks', 'serp'] as $cap) {
            $active = $this->resolveForCapability($cap);
            $capabilities[$cap] = [
                'active_provider' => $active->getName(),
                'active_label' => $active->getLabel(),
                'external_connected' => $active->getName() !== 'first_party_crawl',
            ];
        }

        $logsQuery = CompetitorProviderLog::where('organization_id', $organizationId);
        if ($websiteId) {
            $logsQuery->where('website_id', $websiteId);
        }

        $recentLogs = (clone $logsQuery)->orderByDesc('id')->take(15)->get();
        $totalCalls = (clone $logsQuery)->count();
        $totalCost = round((float) (clone $logsQuery)->sum('estimated_cost_usd'), 4);

        return [
            'providers' => $providers,
            'capabilities' => $capabilities,
            'any_external_connected' => collect($providers)->contains('configured', true),
            'first_party_active' => true,
            'usage' => [
                'total_calls' => $totalCalls,
                'estimated_cost_usd' => $totalCost,
                'recent_logs' => $recentLogs,
            ],
        ];
    }

    /**
     * Execute a provider call with transparent caching and usage audit logging.
     */
    public function cachedCall(
        Website $website,
        string $capability,
        string $cacheKey,
        string $endpointLabel,
        callable $callback,
        bool $forceRefresh = false
    ): array {
        $ttlMinutes = max(5, (int) config('competitors.cache_ttl_minutes', 360));
        $fullKey = "comp_prov:{$website->organization_id}:{$capability}:" . md5($cacheKey);

        if (!$forceRefresh && Cache::has($fullKey)) {
            $cached = Cache::get($fullKey);
            if (is_array($cached)) {
                $this->logCall($website, (string) ($cached['provider'] ?? 'cache'), $capability, $endpointLabel, 'cached', 200, 0, 0.0, null);
                $cached['from_cache'] = true;
                return $cached;
            }
        }

        $start = microtime(true);
        $result = $callback();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $provider = (string) ($result['provider'] ?? 'unknown');
        $available = (bool) ($result['available'] ?? false);
        $status = $available ? 'success' : (str_contains(strtolower((string) ($result['error'] ?? '')), 'not configured') ? 'unconfigured' : 'failed');

        $this->logCall(
            $website,
            $provider,
            $capability,
            $endpointLabel,
            $status,
            $result['http_status'] ?? null,
            $durationMs,
            (float) ($result['cost_usd'] ?? 0.0),
            $result['error'] ?? null
        );

        if ($available) {
            Cache::put($fullKey, $result, now()->addMinutes($ttlMinutes));
        }

        return $result;
    }

    public function logCall(
        Website $website,
        string $provider,
        string $capability,
        string $endpoint,
        string $status,
        ?int $httpStatus = null,
        int $durationMs = 0,
        float $costUsd = 0.0,
        ?string $errorMessage = null
    ): void {
        CompetitorProviderLog::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'provider' => $provider,
            'capability' => $capability,
            'endpoint' => substr($endpoint, 0, 255),
            'status' => $status,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'estimated_cost_usd' => $costUsd,
            'error_message' => $errorMessage ? substr($errorMessage, 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
