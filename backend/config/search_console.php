<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Search Console Configuration
    |--------------------------------------------------------------------------
    */
    'client_id' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
    'client_secret' => env('GOOGLE_SEARCH_CONSOLE_CLIENT_SECRET', env('GOOGLE_CLIENT_SECRET')),
    // Leave null to auto-derive from current request origin (e.g. https://webseoai.vercel.app/api/v1/integrations/google-search-console/callback)
    'redirect_uri' => env('GOOGLE_SEARCH_CONSOLE_REDIRECT_URI'),
    // Frontend dashboard URL to redirect browser after live Google OAuth callback
    'frontend_url' => env('FRONTEND_URL'),
    
    'scopes' => [
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
    ],

    // Fallback sandbox mode when OAuth credentials are not configured in local/test
    'sandbox_fallback' => env('GSC_SANDBOX_FALLBACK', true),

    // Max rows per Search Analytics API request (Google API max is 25000)
    'row_limit' => env('GSC_ROW_LIMIT', 5000),

    // Rate limit and backoff
    'max_retries' => 3,
    'retry_delay_ms' => 1000,
];
