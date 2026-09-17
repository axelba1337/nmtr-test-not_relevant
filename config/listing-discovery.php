<?php

$geminiApiKey = env('CHAT_API_KEY');

if (trim((string) $geminiApiKey) === '') {
    $geminiApiKey = env('GEMINI_API_KEY');
}

return [
    'enabled' => (bool) env('LISTING_DISCOVERY_ENABLED', false),
    'provider' => env('LISTING_DISCOVERY_PROVIDER', 'tavily'),
    'region' => env('LISTING_DISCOVERY_REGION', 'Indonesia'),
    'minimum_active_listings' => (int) env(
        'LISTING_DISCOVERY_MIN_ACTIVE_LISTINGS',
        2,
    ),
    'target_active_listings' => (int) env(
        'LISTING_DISCOVERY_TARGET_ACTIVE_LISTINGS',
        3,
    ),
    'max_products_per_run' => (int) env(
        'LISTING_DISCOVERY_MAX_PRODUCTS_PER_RUN',
        100,
    ),
    'retry_cooldown_hours' => (int) env(
        'LISTING_DISCOVERY_RETRY_COOLDOWN_HOURS',
        24,
    ),
    'research_prompt_max_products' => (int) env(
        'LISTING_RESEARCH_PROMPT_MAX_PRODUCTS',
        25,
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
    'tavily' => [
        'api_key' => env('TAVILY_API_KEY'),
        'base_url' => env(
            'TAVILY_API_BASE_URL',
            'https://api.tavily.com',
        ),
        'search_depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
        'search_max_results' => (int) env(
            'TAVILY_SEARCH_MAX_RESULTS',
            20,
        ),
        'country' => env('TAVILY_SEARCH_COUNTRY', 'indonesia'),
        'extract_enabled' => (bool) env('TAVILY_EXTRACT_ENABLED', true),
        'extract_fallback_only' => (bool) env(
            'TAVILY_EXTRACT_FALLBACK_ONLY',
            true,
        ),
        'extract_depth' => env('TAVILY_EXTRACT_DEPTH', 'basic'),
        'extract_max_urls_per_product' => (int) env(
            'TAVILY_EXTRACT_MAX_URLS_PER_PRODUCT',
            3,
        ),
    ],
    'http_probe' => [
        'enabled' => (bool) env(
            'LISTING_DISCOVERY_HTTP_PROBE_ENABLED',
            true,
        ),
        'timeout' => (int) env(
            'LISTING_DISCOVERY_HTTP_PROBE_TIMEOUT',
            8,
        ),
    ],
    'platform_fallback' => [
        'enabled' => (bool) env(
            'LISTING_DISCOVERY_PLATFORM_FALLBACK_ENABLED',
            true,
        ),
        'max_searches_per_product' => (int) env(
            'LISTING_DISCOVERY_PLATFORM_FALLBACK_MAX_SEARCHES_PER_PRODUCT',
            2,
        ),
    ],
    'search_cache_hours' => (int) env(
        'LISTING_DISCOVERY_SEARCH_CACHE_HOURS',
        24,
    ),
    'gemini' => [
        'provider' => 'gemini',
        'api_key' => $geminiApiKey,
        'model' => env('CHAT_WEB_MODEL', 'gemini-2.5-flash'),
        'base_url' => env(
            'CHAT_API_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta',
        ),
        'grounding_enabled' => (bool) env(
            'CHAT_WEB_GROUNDING_ENABLED',
            true,
        ),
        'max_output_tokens' => (int) env(
            'CHAT_WEB_MAX_OUTPUT_TOKENS',
            1024,
        ),
        'http' => [
            'timeout' => (int) env('LISTING_DISCOVERY_HTTP_TIMEOUT', 30),
            'retries' => (int) env('LISTING_DISCOVERY_HTTP_RETRIES', 1),
            'retry_delay_ms' => (int) env(
                'LISTING_DISCOVERY_HTTP_RETRY_DELAY_MS',
                1000,
            ),
        ],
    ],
];
