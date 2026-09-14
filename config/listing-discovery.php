<?php

return [
    'enabled' => (bool) env('LISTING_DISCOVERY_ENABLED', false),
    'provider' => env('LISTING_DISCOVERY_PROVIDER', 'gemini'),
    'api_key' => env(
        'LISTING_DISCOVERY_API_KEY',
        env('GEMINI_API_KEY'),
    ),
    'model' => env('LISTING_DISCOVERY_MODEL', 'gemini-2.5-flash'),
    'base_url' => env(
        'LISTING_DISCOVERY_API_BASE_URL',
        'https://generativelanguage.googleapis.com/v1beta',
    ),
    'web_grounding_enabled' => (bool) env(
        'LISTING_DISCOVERY_WEB_GROUNDING_ENABLED',
        true,
    ),
    'region' => env('LISTING_DISCOVERY_REGION', 'Indonesia'),
    'minimum_active_listings' => (int) env(
        'LISTING_DISCOVERY_MIN_ACTIVE_LISTINGS',
        2,
    ),
    'target_active_listings' => (int) env(
        'LISTING_DISCOVERY_TARGET_ACTIVE_LISTINGS',
        5,
    ),
    'max_products_per_run' => (int) env(
        'LISTING_DISCOVERY_MAX_PRODUCTS_PER_RUN',
        10,
    ),
    'research_prompt_max_products' => (int) env(
        'LISTING_RESEARCH_PROMPT_MAX_PRODUCTS',
        10,
    ),
    'max_new_listings_per_product' => (int) env(
        'LISTING_DISCOVERY_MAX_NEW_LISTINGS_PER_PRODUCT',
        3,
    ),
    'max_per_platform_per_product' => (int) env(
        'LISTING_DISCOVERY_MAX_PER_PLATFORM_PER_PRODUCT',
        2,
    ),
    'require_approval' => (bool) env(
        'LISTING_DISCOVERY_REQUIRE_APPROVAL',
        true,
    ),
    'auto_import' => (bool) env('LISTING_DISCOVERY_AUTO_IMPORT', false),
    'candidate_retention_days' => (int) env(
        'LISTING_DISCOVERY_CANDIDATE_RETENTION_DAYS',
        7,
    ),
    'schedule' => [
        'enabled' => (bool) env('LISTING_DISCOVERY_SCHEDULE_ENABLED', false),
        'cron' => env('LISTING_DISCOVERY_CRON', '0 2 * * *'),
    ],
    'http' => [
        'timeout' => (int) env('LISTING_DISCOVERY_HTTP_TIMEOUT', 30),
        'retries' => (int) env('LISTING_DISCOVERY_HTTP_RETRIES', 1),
        'retry_delay_ms' => (int) env(
            'LISTING_DISCOVERY_HTTP_RETRY_DELAY_MS',
            1000,
        ),
    ],
    'max_output_tokens' => (int) env(
        'LISTING_DISCOVERY_MAX_OUTPUT_TOKENS',
        4096,
    ),
];
