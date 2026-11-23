<?php

namespace App\Domain\Traffic;

use App\Domain\Competitors\Support\CountryCatalog;
use App\Domain\Crawler\UrlSecurityValidator;
use App\Models\Website;
use App\Models\WebsiteTrafficEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class LiveTrafficService
{
    private const REGION_NAME_OVERRIDES = [
        'BD' => [
            'A' => 'Barishal Division',
            'B' => 'Chattogram Division',
            'C' => 'Dhaka Division',
            'D' => 'Khulna Division',
            'E' => 'Rajshahi Division',
            'F' => 'Rangpur Division',
            'G' => 'Sylhet Division',
            'H' => 'Mymensingh Division',
            '13' => 'Dhaka Division',
            '81' => 'Dhaka Division',
            '84' => 'Chattogram Division',
        ],
    ];

    /**
     * Resolve visitor Country, Region/State, and City from Edge headers (Vercel / Cloudflare)
     * with a cached public IP-geo fallback when edge headers are absent.
     *
     * @return array{country_code: ?string, country_name: ?string, region: ?string, city: ?string}
     */
    public function resolveGeo(Request $request): array
    {
        $countryCode = strtoupper(trim((string) (
            $request->header('x-vercel-ip-country')
            ?: $request->header('cf-ipcountry')
            ?: $request->header('x-country-code')
            ?: ''
        )));
        if ($countryCode === 'XX' || $countryCode === 'T1' || strlen($countryCode) !== 2) {
            $countryCode = '';
        }

        $region = trim(urldecode((string) (
            $request->header('x-vercel-ip-country-region')
            ?: $request->header('cf-region')
            ?: $request->header('x-region')
            ?: ''
        )));

        $city = trim(urldecode((string) (
            $request->header('x-vercel-ip-city')
            ?: $request->header('cf-ipcity')
            ?: $request->header('x-city')
            ?: ''
        )));

        // Expand short region codes like BD 'C' -> 'Dhaka Division'
        if ($countryCode !== '' && $region !== '' && isset(self::REGION_NAME_OVERRIDES[$countryCode][strtoupper($region)])) {
            $region = self::REGION_NAME_OVERRIDES[$countryCode][strtoupper($region)];
        }

        // If edge headers are absent, use cached public IP geolocation for valid public IPs
        if ($countryCode === '' && !app()->runningUnitTests()) {
            $ip = $this->extractClientIp($request);
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $geo = Cache::remember("geo_ip:{$ip}", now()->addHours(24), function () use ($ip) {
                    try {
                        $res = Http::timeout(2)->get("https://ipwho.is/{$ip}");
                        if ($res->successful() && ($res->json('success') === true)) {
                            return [
                                'country_code' => strtoupper((string) ($res->json('country_code') ?? '')),
                                'country_name' => (string) ($res->json('country') ?? ''),
                                'region' => (string) ($res->json('region') ?? ''),
                                'city' => (string) ($res->json('city') ?? ''),
                            ];
                        }
                    } catch (Throwable) {
                        // Ignore lookup timeout
                    }
                    return null;
                });

                if (is_array($geo) && !empty($geo['country_code'])) {
                    return [
                        'country_code' => $geo['country_code'],
                        'country_name' => $geo['country_name'] ?: CountryCatalog::name($geo['country_code']),
                        'region' => $geo['region'] !== '' ? $geo['region'] : null,
                        'city' => $geo['city'] !== '' ? $geo['city'] : null,
                    ];
                }
            }
        }

        return [
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'country_name' => $countryCode !== '' ? CountryCatalog::name($countryCode) : null,
            'region' => $region !== '' ? Str::limit($region, 115, '') : null,
            'city' => $city !== '' ? Str::limit($city, 115, '') : null,
        ];
    }

    /**
     * Parse User-Agent into device type, browser, and OS cleanly.
     *
     * @return array{device_type: string, browser: string, os: string}
     */
    public function parseUserAgent(?string $ua): array
    {
        $uaStr = (string) ($ua ?? '');
        $lower = strtolower($uaStr);

        $device = 'desktop';
        if (preg_match('/ipad|tablet|playbook|silk/i', $uaStr)) {
            $device = 'tablet';
        } elseif (preg_match('/mobile|iphone|ipod|android.*mobile|windows phone|blackberry/i', $uaStr)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        if (str_contains($lower, 'edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($lower, 'opr/') || str_contains($lower, 'opera')) {
            $browser = 'Opera';
        } elseif (str_contains($lower, 'firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($lower, 'chrome/') || str_contains($lower, 'crios')) {
            $browser = 'Chrome';
        } elseif (str_contains($lower, 'safari/')) {
            $browser = 'Safari';
        }

        $os = 'Other';
        if (str_contains($lower, 'iphone') || str_contains($lower, 'ipad') || str_contains($lower, 'ios')) {
            $os = 'iOS';
        } elseif (str_contains($lower, 'android')) {
            $os = 'Android';
        } elseif (str_contains($lower, 'windows')) {
            $os = 'Windows';
        } elseif (str_contains($lower, 'mac os x') || str_contains($lower, 'macintosh')) {
            $os = 'macOS';
        } elseif (str_contains($lower, 'linux')) {
            $os = 'Linux';
        }

        return [
            'device_type' => $device,
            'browser' => $browser,
            'os' => $os,
        ];
    }

    /**
     * Detect automated bots/crawlers so live traffic counts ONLY real human visitors.
     */
    public function isBotUserAgent(?string $ua): bool
    {
        $lower = strtolower(trim((string) ($ua ?? '')));
        if ($lower === '') {
            return false;
        }

        foreach ([
            'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot', 'slurp',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'petalbot', 'bytespider',
            'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp/', 'slackbot',
            'telegrambot', 'discordbot', 'applebot', 'headlesschrome', 'lighthouse',
            'pagespeed', 'gtmetrix', 'pingdom', 'uptimerobot', 'statuscake', 'vercel-screenshot',
            'curl/', 'wget/', 'python-requests', 'go-http-client', 'scrapy', 'webseoai',
        ] as $bot) {
            if (str_contains($lower, $bot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inspect the live website HTML (SSRF-safe) to verify whether the Web SEO AI tracker script
     * is installed AND detect existing Google Analytics 4 (G-...) or GTM (GTM-...) tags.
     */
    public function verifyLiveInstallation(Website $website): array
    {
        $token = $website->ensureTrackingToken();
        $url = $website->target_url ?: "https://{$website->domain}";

        try {
            if (!app()->runningUnitTests()) {
                UrlSecurityValidator::validate($url, $website->domain);
            }

            $res = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ])->timeout(8)->get($url);

            if (!$res->successful()) {
                return [
                    'reachable' => false,
                    'http_status' => $res->status(),
                    'tracker_installed' => false,
                    'detected_ga4_id' => null,
                    'detected_gtm_id' => null,
                    'message' => "Could not fetch {$url} (HTTP {$res->status()}).",
                ];
            }

            $html = $res->body();
            $trackerInstalled = str_contains($html, $token);

            $ga4Id = null;
            if (preg_match('/\b(G-[A-Z0-9]{6,14})\b/', $html, $m)) {
                $ga4Id = $m[1];
            }

            $gtmId = null;
            if (preg_match('/\b(GTM-[A-Z0-9]{5,12})\b/', $html, $m)) {
                $gtmId = $m[1];
            }

            $msg = $trackerInstalled
                ? "Verified! Web SEO AI live tracker ({$token}) is active on {$website->domain}."
                : ($ga4Id
                    ? "Web SEO AI script is NOT installed on {$website->domain} yet — however Google Analytics 4 ({$ga4Id}) is already active! Connect GA4 in 1 click OR paste the 1-line script into your site <head>."
                    : "Web SEO AI tracker script was NOT found in {$website->domain}'s live HTML yet. Paste the 1-line script into your site <head> and click Verify again.");

            return [
                'reachable' => true,
                'http_status' => $res->status(),
                'tracker_installed' => $trackerInstalled,
                'detected_ga4_id' => $ga4Id,
                'detected_gtm_id' => $gtmId,
                'checked_url' => $url,
                'checked_at' => now()->toIso8601String(),
                'message' => $msg,
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'http_status' => null,
                'tracker_installed' => false,
                'detected_ga4_id' => null,
                'detected_gtm_id' => null,
                'message' => 'Live check could not reach website: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Record a live visitor event (pageview or heartbeat) using privacy-safe visitor hashing.
     */
    public function recordEvent(Website $website, Request $request, array $payload, bool $isTest = false): WebsiteTrafficEvent
    {
        $ip = $this->extractClientIp($request) ?: '0.0.0.0';
        $ua = (string) ($request->userAgent() ?? '');
        // Prefer anonymous browser-generated client_id (accurate behind mobile CGNAT/office Wi-Fi) hashed with app key
        $clientId = trim((string) ($payload['client_id'] ?? $payload['vid'] ?? ''));
        $visitorSeed = $clientId !== ''
            ? [$website->id, 'cid', $clientId, (string) config('app.key', 'web-seo-ai')]
            : [$website->id, $ip, $ua, now()->toDateString(), (string) config('app.key', 'web-seo-ai')];
        $visitorHash = hash('sha256', implode('|', $visitorSeed));

        $geo = $this->resolveGeo($request);
        $uaMeta = $this->parseUserAgent($ua);

        $path = trim((string) ($payload['path'] ?? '/'));
        if ($path === '' || !str_starts_with($path, '/')) {
            $parsedPath = (string) parse_url($path, PHP_URL_PATH);
            $path = $parsedPath !== '' ? $parsedPath : '/';
        }

        $refUrl = trim((string) ($payload['referrer'] ?? ''));
        $refDomain = 'Direct';
        if ($refUrl !== '' && preg_match('#^https?://#i', $refUrl)) {
            $host = preg_replace('/^www\./i', '', strtolower((string) parse_url($refUrl, PHP_URL_HOST)));
            $ownDomain = preg_replace('/^www\./i', '', strtolower((string) $website->domain));
            if ($host !== '' && $host !== $ownDomain && !str_ends_with($host, '.' . $ownDomain)) {
                $refDomain = $host;
            }
        } elseif (!empty($payload['utm_source'])) {
            $refDomain = strtolower(trim((string) $payload['utm_source']));
        }

        $eventType = in_array($payload['event_type'] ?? 'pageview', ['pageview', 'heartbeat'], true)
            ? (string) $payload['event_type']
            : 'pageview';

        return WebsiteTrafficEvent::create([
            'organization_id' => $website->organization_id,
            'website_id' => $website->id,
            'visitor_hash' => $visitorHash,
            'session_id' => isset($payload['session_id']) ? Str::limit((string) $payload['session_id'], 64, '') : substr($visitorHash, 0, 24),
            'event_type' => $eventType,
            'is_test' => $isTest,
            'path' => Str::limit($path, 500, ''),
            'page_title' => isset($payload['title']) && trim((string) $payload['title']) !== '' ? Str::limit(trim((string) $payload['title']), 240, '') : null,
            'referrer_domain' => Str::limit($refDomain, 240, ''),
            'referrer_url' => $refUrl !== '' ? Str::limit($refUrl, 1000, '') : null,
            'utm_source' => isset($payload['utm_source']) ? Str::limit((string) $payload['utm_source'], 110, '') : null,
            'utm_medium' => isset($payload['utm_medium']) ? Str::limit((string) $payload['utm_medium'], 110, '') : null,
            'utm_campaign' => isset($payload['utm_campaign']) ? Str::limit((string) $payload['utm_campaign'], 110, '') : null,
            'country_code' => $geo['country_code'],
            'country_name' => $geo['country_name'],
            'region' => $geo['region'],
            'city' => $geo['city'],
            'device_type' => $uaMeta['device_type'],
            'browser' => $uaMeta['browser'],
            'os' => $uaMeta['os'],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Build comprehensive real-time traffic analytics payload with Country, Region, City,
     * Active Visitors Now (5m window), Timeline, Top Pages, Referrers, Devices, and Live Feed.
     */
    public function getRealtimeStats(
        Website $website,
        string $range = '24h',
        ?string $countryFilter = null,
        string $baseOrigin = '',
        bool $includeTest = false
    ): array {
        $token = $website->ensureTrackingToken();
        $now = now();
        $activeSince = (clone $now)->subMinutes(5);
        $since30m = (clone $now)->subMinutes(30);

        $rangeStart = match ($range) {
            '30m' => (clone $now)->subMinutes(30),
            '7d' => (clone $now)->subDays(7),
            '30d' => (clone $now)->subDays(30),
            default => (clone $now)->subHours(24),
        };

        $countryFilterUpper = $countryFilter ? strtoupper(trim($countryFilter)) : null;

        // Strictly separate real website visitor events from synthetic test pings!
        $eventsQuery = WebsiteTrafficEvent::where('website_id', $website->id)
            ->where('occurred_at', '>=', $rangeStart);
        if (!$includeTest) {
            $eventsQuery->where('is_test', false);
        }

        $events = $eventsQuery
            ->orderByDesc('occurred_at')
            ->limit(5000)
            ->get();

        // Active visitors in last 5 minutes (all countries, or filtered if requested)
        $activeEvents = $events->filter(fn ($e) => $e->occurred_at && $e->occurred_at->greaterThanOrEqualTo($activeSince));
        $activeVisitorsNow = $activeEvents->pluck('visitor_hash')->unique()->count();

        $events30m = $events->filter(fn ($e) => $e->occurred_at && $e->occurred_at->greaterThanOrEqualTo($since30m));
        $pageviews30m = $events30m->where('event_type', 'pageview')->count();
        $visitors30m = $events30m->pluck('visitor_hash')->unique()->count();

        $pageviewsRange = $events->where('event_type', 'pageview')->count();
        $uniqueVisitorsRange = $events->pluck('visitor_hash')->unique()->count();

        // 1. Country breakdown (always computed over all events in range so user can click countries)
        $countryMap = [];
        foreach ($events as $e) {
            $cc = $e->country_code ?: 'UN';
            $cName = $e->country_name ?: ($cc === 'UN' ? 'Unknown / Local' : CountryCatalog::name($cc));
            if (!isset($countryMap[$cc])) {
                $countryMap[$cc] = [
                    'country_code' => $cc,
                    'country_name' => $cName,
                    'visitors_set' => [],
                    'active_set' => [],
                    'pageviews' => 0,
                    'regions' => [],
                ];
            }
            $countryMap[$cc]['visitors_set'][$e->visitor_hash] = true;
            if ($e->occurred_at && $e->occurred_at->greaterThanOrEqualTo($activeSince)) {
                $countryMap[$cc]['active_set'][$e->visitor_hash] = true;
            }
            if ($e->event_type === 'pageview') {
                $countryMap[$cc]['pageviews']++;
            }
            if (!empty($e->region)) {
                $countryMap[$cc]['regions'][$e->region] = ($countryMap[$cc]['regions'][$e->region] ?? 0) + 1;
            }
        }

        $totalUniqueForShare = max(1, $uniqueVisitorsRange);
        $byCountry = [];
        foreach ($countryMap as $row) {
            arsort($row['regions']);
            $vCount = count($row['visitors_set']);
            $byCountry[] = [
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name'],
                'visitors' => $vCount,
                'active_now' => count($row['active_set']),
                'pageviews' => $row['pageviews'],
                'share_pct' => round(($vCount / $totalUniqueForShare) * 100, 1),
                'top_regions' => array_slice(array_keys($row['regions']), 0, 3),
            ];
        }
        usort($byCountry, fn ($a, $b) => ($b['visitors'] <=> $a['visitors']) ?: ($b['pageviews'] <=> $a['pageviews']));

        // Apply optional country filter to Region, Pages, Referrers, Devices, and Feed
        $scopedEvents = $countryFilterUpper
            ? $events->filter(fn ($e) => strtoupper((string) ($e->country_code ?: 'UN')) === $countryFilterUpper)
            : $events;

        // 2. Region & City breakdown
        $regionMap = [];
        foreach ($scopedEvents as $e) {
            $cc = $e->country_code ?: 'UN';
            $cName = $e->country_name ?: ($cc === 'UN' ? 'Unknown / Local' : CountryCatalog::name($cc));
            $reg = $e->region ?: 'Unknown Region';
            $city = $e->city ?: 'Unknown City';
            $key = "{$cc}|{$reg}|{$city}";
            if (!isset($regionMap[$key])) {
                $regionMap[$key] = [
                    'country_code' => $cc,
                    'country_name' => $cName,
                    'region' => $reg,
                    'city' => $city,
                    'visitors_set' => [],
                    'active_set' => [],
                    'pageviews' => 0,
                ];
            }
            $regionMap[$key]['visitors_set'][$e->visitor_hash] = true;
            if ($e->occurred_at && $e->occurred_at->greaterThanOrEqualTo($activeSince)) {
                $regionMap[$key]['active_set'][$e->visitor_hash] = true;
            }
            if ($e->event_type === 'pageview') {
                $regionMap[$key]['pageviews']++;
            }
        }
        $byRegion = [];
        foreach ($regionMap as $r) {
            $byRegion[] = [
                'country_code' => $r['country_code'],
                'country_name' => $r['country_name'],
                'region' => $r['region'],
                'city' => $r['city'],
                'visitors' => count($r['visitors_set']),
                'active_now' => count($r['active_set']),
                'pageviews' => $r['pageviews'],
            ];
        }
        usort($byRegion, fn ($a, $b) => ($b['visitors'] <=> $a['visitors']) ?: ($b['pageviews'] <=> $a['pageviews']));

        // 3. Top Pages
        $pageMap = [];
        foreach ($scopedEvents as $e) {
            $p = $e->path ?: '/';
            if (!isset($pageMap[$p])) {
                $pageMap[$p] = [
                    'path' => $p,
                    'page_title' => $e->page_title,
                    'visitors_set' => [],
                    'active_set' => [],
                    'pageviews' => 0,
                ];
            }
            if (!$pageMap[$p]['page_title'] && $e->page_title) {
                $pageMap[$p]['page_title'] = $e->page_title;
            }
            $pageMap[$p]['visitors_set'][$e->visitor_hash] = true;
            if ($e->occurred_at && $e->occurred_at->greaterThanOrEqualTo($activeSince)) {
                $pageMap[$p]['active_set'][$e->visitor_hash] = true;
            }
            if ($e->event_type === 'pageview') {
                $pageMap[$p]['pageviews']++;
            }
        }
        $topPages = [];
        foreach ($pageMap as $p) {
            $topPages[] = [
                'path' => $p['path'],
                'page_title' => $p['page_title'],
                'visitors' => count($p['visitors_set']),
                'active_now' => count($p['active_set']),
                'pageviews' => $p['pageviews'],
            ];
        }
        usort($topPages, fn ($a, $b) => ($b['pageviews'] <=> $a['pageviews']) ?: ($b['visitors'] <=> $a['visitors']));

        // 4. Top Referrers
        $refMap = [];
        foreach ($scopedEvents->where('event_type', 'pageview') as $e) {
            $ref = $e->referrer_domain ?: 'Direct';
            if (!isset($refMap[$ref])) {
                $refMap[$ref] = ['source' => $ref, 'visitors_set' => [], 'pageviews' => 0];
            }
            $refMap[$ref]['visitors_set'][$e->visitor_hash] = true;
            $refMap[$ref]['pageviews']++;
        }
        $topReferrers = [];
        foreach ($refMap as $rf) {
            $topReferrers[] = [
                'source' => $rf['source'],
                'visitors' => count($rf['visitors_set']),
                'pageviews' => $rf['pageviews'],
            ];
        }
        usort($topReferrers, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

        // 5. Devices & Browsers
        $deviceCounts = ['desktop' => [], 'mobile' => [], 'tablet' => []];
        $browserCounts = [];
        foreach ($scopedEvents as $e) {
            $dev = in_array($e->device_type, ['desktop', 'mobile', 'tablet'], true) ? $e->device_type : 'desktop';
            $deviceCounts[$dev][$e->visitor_hash] = true;
            $br = $e->browser ?: 'Other';
            $browserCounts[$br][$e->visitor_hash] = true;
        }
        $scopedUniqueTotal = max(1, $scopedEvents->pluck('visitor_hash')->unique()->count());
        $devices = [];
        foreach ($deviceCounts as $dev => $set) {
            $cnt = count($set);
            $devices[] = [
                'device' => $dev,
                'visitors' => $cnt,
                'share_pct' => round(($cnt / $scopedUniqueTotal) * 100, 1),
            ];
        }
        $browsers = [];
        foreach ($browserCounts as $br => $set) {
            $browsers[] = ['browser' => $br, 'visitors' => count($set)];
        }
        usort($browsers, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

        // 6. Timeline Buckets
        $timeline = $this->buildTimeline($scopedEvents, $range, $now);

        // 7. Live Visitor Stream (most recent 25 pageviews)
        $liveFeed = $scopedEvents
            ->where('event_type', 'pageview')
            ->take(25)
            ->map(function (WebsiteTrafficEvent $e) use ($now, $activeSince) {
                return [
                    'id' => $e->id,
                    'path' => $e->path,
                    'page_title' => $e->page_title,
                    'country_code' => $e->country_code ?: 'UN',
                    'country_name' => $e->country_name ?: 'Unknown / Local',
                    'region' => $e->region,
                    'city' => $e->city,
                    'referrer_domain' => $e->referrer_domain ?: 'Direct',
                    'device_type' => $e->device_type,
                    'browser' => $e->browser,
                    'os' => $e->os,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'seconds_ago' => $e->occurred_at ? max(0, (int) $e->occurred_at->diffInSeconds($now)) : 0,
                    'is_active' => $e->occurred_at ? $e->occurred_at->greaterThanOrEqualTo($activeSince) : false,
                ];
            })
            ->values()
            ->toArray();

        // All-time tracker health (strictly separating real website events vs test pings)
        $realEventsAllTime = WebsiteTrafficEvent::where('website_id', $website->id)->where('is_test', false)->count();
        $testEventsAllTime = WebsiteTrafficEvent::where('website_id', $website->id)->where('is_test', true)->count();
        $lastRealEventAt = WebsiteTrafficEvent::where('website_id', $website->id)->where('is_test', false)->max('occurred_at');

        $origin = rtrim($baseOrigin ?: (config('search_console.frontend_url') ?: url('/')), '/');
        $scriptUrl = "{$origin}/api/v1/t/{$token}.js";

        return [
            'data_source' => 'first_party_tracker',
            'include_test' => $includeTest,
            'range' => $range,
            'country_filter' => $countryFilterUpper,
            'summary' => [
                'active_visitors_now' => $activeVisitorsNow,
                'active_pages_now' => $activeEvents->pluck('path')->unique()->count(),
                'pageviews_30m' => $pageviews30m,
                'visitors_30m' => $visitors30m,
                'pageviews_range' => $pageviewsRange,
                'unique_visitors_range' => $uniqueVisitorsRange,
                'countries_count' => count($byCountry),
                'top_country' => $byCountry[0] ?? null,
            ],
            'timeline' => $timeline,
            'by_country' => array_slice($byCountry, 0, 30),
            'by_region' => array_slice($byRegion, 0, 30),
            'top_pages' => array_slice($topPages, 0, 15),
            'top_referrers' => array_slice($topReferrers, 0, 12),
            'devices' => $devices,
            'browsers' => array_slice($browsers, 0, 6),
            'live_feed' => $liveFeed,
            'tracker' => [
                'tracking_token' => $token,
                'script_url' => $scriptUrl,
                'script_tag' => "<script defer src=\"{$scriptUrl}\"></script>",
                'has_events' => $realEventsAllTime > 0,
                'has_real_events' => $realEventsAllTime > 0,
                'real_events_all_time' => $realEventsAllTime,
                'test_events_all_time' => $testEventsAllTime,
                'total_events_all_time' => $realEventsAllTime + $testEventsAllTime,
                'last_event_at' => $lastRealEventAt ? Carbon::parse($lastRealEventAt)->toIso8601String() : null,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Render tiny, ZERO-PREFLIGHT CORS-proof, privacy-first, SPA-aware JavaScript live tracker (< 1.8 KB).
     * Uses text/plain CORS simple requests + 1x1 GIF pixel fallback so external domains are NEVER blocked by CORS.
     */
    public function renderTrackerScript(Website $website, string $collectUrl, ?string $pixelUrl = null): string
    {
        $token = addslashes($website->ensureTrackingToken());
        $endpoint = addslashes($collectUrl);
        $pixel = addslashes($pixelUrl ?: preg_replace('#/collect$#', '/p.gif', $collectUrl));

        return <<<JS
(function(){
  try {
    var T = "{$token}";
    var E = "{$endpoint}";
    var P = "{$pixel}";
    var V = "";
    try {
      V = localStorage.getItem("_wsa_vid") || "";
      if (!V) { V = "v_" + Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem("_wsa_vid", V); }
    } catch (e) { V = "v_" + Math.random().toString(36).slice(2); }
    var S = "s_" + Math.random().toString(36).slice(2) + Date.now().toString(36);
    var lastPath = "";
    function getParam(k){try{return new URLSearchParams(location.search).get(k)||"";}catch(e){return "";}}
    function sendPixel(type){
      try {
        var qs = "t=" + encodeURIComponent(T)
          + "&e=" + encodeURIComponent(type)
          + "&v=" + encodeURIComponent(V)
          + "&s=" + encodeURIComponent(S)
          + "&p=" + encodeURIComponent(location.pathname + location.search)
          + "&ti=" + encodeURIComponent((document.title || "").slice(0, 150))
          + "&r=" + encodeURIComponent((document.referrer || "").slice(0, 300));
        var img = new Image();
        img.src = P + "?" + qs + "&_=" + Date.now();
      } catch (err) {}
    }
    function send(type){
      var payload = JSON.stringify({
        token: T,
        client_id: V,
        session_id: S,
        event_type: type,
        path: location.pathname + location.search,
        title: (document.title || "").slice(0, 200),
        referrer: document.referrer || "",
        utm_source: getParam("utm_source"),
        utm_medium: getParam("utm_medium"),
        utm_campaign: getParam("utm_campaign")
      });
      // text/plain is a CORS Simple Request (ZERO preflight OPTIONS!) so external sites are NEVER blocked
      var sent = false;
      try {
        if (navigator.sendBeacon) {
          sent = navigator.sendBeacon(E, new Blob([payload], {type: "text/plain;charset=UTF-8"}));
        }
      } catch (e) {}
      if (!sent) {
        try {
          fetch(E, {method: "POST", body: payload, headers: {"Content-Type": "text/plain;charset=UTF-8"}, keepalive: true, mode: "no-cors"}).catch(function(){ sendPixel(type); });
        } catch (e) {
          sendPixel(type);
        }
      }
    }
    function trackPage(){
      var cur = location.pathname + location.search;
      if (cur === lastPath) return;
      lastPath = cur;
      send("pageview");
    }
    trackPage();
    var origPush = history.pushState;
    if (origPush) {
      history.pushState = function(){ origPush.apply(this, arguments); setTimeout(trackPage, 50); };
    }
    window.addEventListener("popstate", function(){ setTimeout(trackPage, 50); });
    setInterval(function(){ if (document.visibilityState === "visible") send("heartbeat"); }, 20000);
  } catch (e) {}
})();
JS;
    }

    private function buildTimeline($events, string $range, Carbon $now): array
    {
        $buckets = [];

        if ($range === '30m') {
            for ($i = 29; $i >= 0; $i--) {
                $dt = (clone $now)->subMinutes($i);
                $key = $dt->format('Y-m-d H:i');
                $buckets[$key] = ['label' => $dt->format('H:i'), 'pageviews' => 0, 'visitors_set' => []];
            }
            $fmt = 'Y-m-d H:i';
        } elseif ($range === '7d' || $range === '30d') {
            $days = $range === '7d' ? 7 : 30;
            for ($i = $days - 1; $i >= 0; $i--) {
                $dt = (clone $now)->subDays($i);
                $key = $dt->format('Y-m-d');
                $buckets[$key] = ['label' => $dt->format('M d'), 'pageviews' => 0, 'visitors_set' => []];
            }
            $fmt = 'Y-m-d';
        } else {
            for ($i = 23; $i >= 0; $i--) {
                $dt = (clone $now)->subHours($i);
                $key = $dt->format('Y-m-d H');
                $buckets[$key] = ['label' => $dt->format('H:00'), 'pageviews' => 0, 'visitors_set' => []];
            }
            $fmt = 'Y-m-d H';
        }

        foreach ($events as $e) {
            if (!$e->occurred_at) {
                continue;
            }
            $k = $e->occurred_at->format($fmt);
            if (!isset($buckets[$k])) {
                continue;
            }
            $buckets[$k]['visitors_set'][$e->visitor_hash] = true;
            if ($e->event_type === 'pageview') {
                $buckets[$k]['pageviews']++;
            }
        }

        $out = [];
        foreach ($buckets as $b) {
            $out[] = [
                'label' => $b['label'],
                'pageviews' => $b['pageviews'],
                'visitors' => count($b['visitors_set']),
            ];
        }
        return $out;
    }

    private function extractClientIp(Request $request): ?string
    {
        $forwarded = (string) ($request->header('x-forwarded-for') ?: $request->header('cf-connecting-ip') ?: '');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $request->ip();
    }
}
