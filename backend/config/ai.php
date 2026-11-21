<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Driver
    |--------------------------------------------------------------------------
    | Supported: "gemini", "openai", "deterministic_studio"
    |
    | When no external API key is supplied, Web SEO AI gracefully defaults to
    | "deterministic_studio" for resilient local testing and zero-downtime execution.
    */
    'default_driver' => env('AI_PROVIDER', 'deterministic_studio'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'timeout' => 30,
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => 30,
    ],

    'fallback_enabled' => env('AI_FALLBACK_ENABLED', true),
];
