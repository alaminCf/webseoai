<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Competitor Intelligence Data Providers
    |--------------------------------------------------------------------------
    | All credentials are read strictly from server environment variables.
    | Never expose provider secrets in frontend responses.
    */
    'cache_ttl_minutes' => (int) env('COMPETITOR_CACHE_TTL_MINUTES', 360),
    'max_comparison_competitors' => (int) env('COMPETITOR_MAX_COMPARISON', 5),
    'http_timeout_seconds' => (int) env('COMPETITOR_HTTP_TIMEOUT', 15),

    'providers' => [
        'dataforseo' => [
            'label' => 'DataForSEO v3',
            'login' => env('DATAFORSEO_LOGIN', ''),
            'password' => env('DATAFORSEO_PASSWORD', ''),
            'base_url' => rtrim((string) env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com/v3'), '/'),
            'capabilities' => ['discovery', 'domain_metrics', 'keywords', 'backlinks', 'serp'],
            'docs_url' => 'https://docs.dataforseo.com/v3/',
        ],
        'semrush' => [
            'label' => 'Semrush Analytics API',
            'api_key' => env('SEMRUSH_API_KEY', ''),
            'base_url' => rtrim((string) env('SEMRUSH_BASE_URL', 'https://api.semrush.com'), '/'),
            'capabilities' => ['discovery', 'domain_metrics', 'keywords', 'backlinks'],
            'docs_url' => 'https://developer.semrush.com/api/',
        ],
        'ahrefs' => [
            'label' => 'Ahrefs API v3',
            'api_key' => env('AHREFS_API_KEY', ''),
            'base_url' => rtrim((string) env('AHREFS_BASE_URL', 'https://api.ahrefs.com/v3'), '/'),
            'capabilities' => ['domain_metrics', 'backlinks'],
            'docs_url' => 'https://docs.ahrefs.com/docs/api/reference/introduction',
        ],
        'serpapi' => [
            'label' => 'SerpApi',
            'api_key' => env('SERPAPI_KEY', ''),
            'base_url' => rtrim((string) env('SERPAPI_BASE_URL', 'https://serpapi.com'), '/'),
            'capabilities' => ['serp', 'discovery'],
            'docs_url' => 'https://serpapi.com/search-api',
        ],
        'serper' => [
            'label' => 'Serper.dev (Google Search API)',
            'api_key' => env('SERPER_API_KEY')
                ?: env('SERPER_KEY')
                ?: env('SERPER_API')
                ?: env('SERPER_TOKEN')
                ?: env('NEXT_PUBLIC_SERPER_API_KEY')
                ?: '',
            'base_url' => rtrim((string) env('SERPER_BASE_URL', 'https://google.serper.dev'), '/'),
            'capabilities' => ['serp', 'discovery'],
            'docs_url' => 'https://serper.dev/',
        ],
    ],

    // Country ISO2 to DataForSEO location_code & Semrush database mapping
    'country_map' => [
        'US' => ['location_code' => 2840, 'semrush_db' => 'us', 'gl' => 'us', 'name' => 'United States'],
        'GB' => ['location_code' => 2826, 'semrush_db' => 'uk', 'gl' => 'uk', 'name' => 'United Kingdom'],
        'CA' => ['location_code' => 2124, 'semrush_db' => 'ca', 'gl' => 'ca', 'name' => 'Canada'],
        'AU' => ['location_code' => 2036, 'semrush_db' => 'au', 'gl' => 'au', 'name' => 'Australia'],
        'BD' => ['location_code' => 2050, 'semrush_db' => 'bd', 'gl' => 'bd', 'name' => 'Bangladesh'],
        'IN' => ['location_code' => 2356, 'semrush_db' => 'in', 'gl' => 'in', 'name' => 'India'],
        'DE' => ['location_code' => 2276, 'semrush_db' => 'de', 'gl' => 'de', 'name' => 'Germany'],
        'FR' => ['location_code' => 2250, 'semrush_db' => 'fr', 'gl' => 'fr', 'name' => 'France'],
        'AE' => ['location_code' => 2784, 'semrush_db' => 'ae', 'gl' => 'ae', 'name' => 'United Arab Emirates'],
        'SG' => ['location_code' => 2702, 'semrush_db' => 'sg', 'gl' => 'sg', 'name' => 'Singapore'],
    ],

    // Generic portals/social/job/classified sites excluded from "Direct Business Competitor" classification
    'non_direct_portal_domains' => [
        'wikipedia.org', 'wikimedia.org', 'britannica.com', 'youtube.com', 'facebook.com', 'instagram.com',
        'linkedin.com', 'twitter.com', 'x.com', 'reddit.com', 'quora.com', 'pinterest.com', 'tiktok.com',
        'amazon.com', 'yelp.com', 'yellowpages.com', 'bbb.org', 'trustpilot.com', 'medium.com', 'forbes.com',
        'github.com', 'google.com', 'apple.com', 'play.google.com', 'apps.apple.com', 'indeed.com',
        'glassdoor.com', 'bdjobs.com', 'crunchbase.com', 'zoominfo.com', 'justdial.com', 'indiamart.com',
        'daraz.com.bd', 'bikroy.com', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'tripadvisor.com',
        'angi.com', 'thumbtack.com', 'homeadvisor.com', 'taskrabbit.com', 'craigslist.org',
    ],
];
