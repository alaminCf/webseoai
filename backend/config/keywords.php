<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Keyword Research Provider Configuration
    |--------------------------------------------------------------------------
    */
    'default' => env('KEYWORD_PROVIDER', 'sandbox'),

    'providers' => [
        'sandbox' => [
            'name' => 'Web SEO AI Sandbox Provider',
            'version' => '1.0',
        ],

        'dataforseo' => [
            'login' => env('DATAFORSEO_LOGIN'),
            'password' => env('DATAFORSEO_PASSWORD'),
            'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com/v3'),
        ],
    ],

    // Default targeting
    'defaults' => [
        'country' => 'US',
        'language' => 'en',
    ],
];
