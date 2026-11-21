<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS Launch & Public Registration
    |--------------------------------------------------------------------------
    */
    'public_registration_enabled' => env('SAAS_PUBLIC_REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Stripe / Payment Provider Configuration
    |--------------------------------------------------------------------------
    */
    'stripe' => [
        'key' => env('STRIPE_KEY', 'pk_test_mock_web_seo_ai'),
        'secret' => env('STRIPE_SECRET', 'sk_test_mock_web_seo_ai'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', 'whsec_test_mock_secret'),
        'sandbox_mode' => env('STRIPE_SANDBOX_MODE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans & Quota Limits
    |--------------------------------------------------------------------------
    */
    'plans' => [
        'starter' => [
            'id' => 'starter',
            'name' => 'Starter Plan',
            'description' => 'For independent consultants and boutique sites.',
            'price_monthly' => 49,
            'price_yearly' => 470,
            'max_websites' => 3,
            'max_crawl_pages' => 250,
            'crawl_frequency' => 'weekly',
            'max_ai_tokens_monthly' => 50_000,
            'max_articles_monthly' => 10,
            'max_team_seats' => 2,
            'supports_cms_write' => false,
            'supports_white_label' => false,
            'supports_webhooks' => false,
            'features' => [
                'Up to 3 active websites',
                '250 crawled pages per audit',
                'Weekly technical audit schedule',
                '50,000 AI tokens / month',
                '10 AI content articles / month',
                'Search Console analytics',
                '2 team member seats',
                'Standard PDF & CSV exports',
            ],
        ],

        'agency_pro' => [
            'id' => 'agency_pro',
            'name' => 'Agency Pro',
            'description' => 'Complete automation and client management for growth agencies.',
            'price_monthly' => 199,
            'price_yearly' => 1990,
            'max_websites' => 15,
            'max_crawl_pages' => 5_000,
            'crawl_frequency' => 'daily',
            'max_ai_tokens_monthly' => 500_000,
            'max_articles_monthly' => 100,
            'max_team_seats' => 10,
            'supports_cms_write' => true,
            'supports_white_label' => true,
            'supports_webhooks' => true,
            'features' => [
                'Up to 15 active websites',
                '5,000 crawled pages per audit',
                'Daily automated crawl schedule',
                '500,000 AI tokens / month',
                '100 AI content articles / month',
                'Direct WordPress & Shopify CMS execution',
                'White-label branded reports (logo, theme)',
                'Client workspaces & sign-off approvals',
                '10 team member seats',
                'Autopilot engine & webhooks',
            ],
        ],

        'enterprise' => [
            'id' => 'enterprise',
            'name' => 'Enterprise Agency',
            'description' => 'Large-scale multi-client SEO operations with dedicated capacity.',
            'price_monthly' => 599,
            'price_yearly' => 5990,
            'max_websites' => 100,
            'max_crawl_pages' => 50_000,
            'crawl_frequency' => 'unlimited',
            'max_ai_tokens_monthly' => 5_000_000,
            'max_articles_monthly' => 1_000,
            'max_team_seats' => 50,
            'supports_cms_write' => true,
            'supports_white_label' => true,
            'supports_webhooks' => true,
            'features' => [
                'Up to 100 active websites',
                '50,000 crawled pages per audit',
                'On-demand & real-time crawl schedules',
                '5,000,000 AI tokens / month',
                '1,000 AI content articles / month',
                'Full CMS integrations with rollback logs',
                'Custom white-label agency branding',
                'Unlimited client workspaces & sign-off links',
                '50 team member seats with website-level ACL',
                'Priority queue processing & dedicated support',
            ],
        ],

        'agency_internal' => [
            'id' => 'agency_internal',
            'name' => 'Techboloy Internal',
            'description' => 'Unrestricted deployment tier for internal operations.',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'max_websites' => 9999,
            'max_crawl_pages' => 100_000,
            'crawl_frequency' => 'unlimited',
            'max_ai_tokens_monthly' => 99_999_999,
            'max_articles_monthly' => 99_999,
            'max_team_seats' => 999,
            'supports_cms_write' => true,
            'supports_white_label' => true,
            'supports_webhooks' => true,
            'features' => [
                'Internal unrestricted capacity',
            ],
        ],
    ],
];
