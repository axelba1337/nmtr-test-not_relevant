<?php

$targets = array_values(array_filter(array_map(
    static fn (string $target): string => strtolower(trim($target)),
    explode(',', (string) env(
        'MARKETPLACE_TARGETS',
        'tokopedia,shopee,olx,facebook,carousell',
    )),
)));

return [
    'provider' => env('MARKETPLACE_PROVIDER', 'manual-import'),
    'sync_mode' => env('MARKETPLACE_SYNC_MODE', 'manual'),
    'targets' => $targets,
    'page_size' => max(1, min(
        500,
        (int) env('MARKETPLACE_PAGE_SIZE', 100),
    )),
    'max_pages' => max(1, min(
        100,
        (int) env('MARKETPLACE_MAX_PAGES', 25),
    )),
    'lock_seconds' => max(
        10,
        (int) env('MARKETPLACE_SYNC_LOCK_SECONDS', 300),
    ),

    /*
     * Test-only fixture data. This is never a normal development or
     * production listing provider.
     */
    'demo' => [
        'listings' => [
            'tokopedia' => [[
                'product_reference' => 'nvidia|rtx 3060|12 gb||nvidia geforce rtx 3060',
                'external_listing_id' => 'demo-rtx3060-001',
                'title' => 'RTX 3060 12GB Bekas - Data Demo',
                'description' => 'Listing portofolio, bukan penawaran nyata.',
                'price' => 2500000,
                'currency' => 'IDR',
                'condition' => 'used',
                'seller_name' => 'Toko Demo Surabaya',
                'seller_type' => 'store',
                'location' => 'Surabaya',
                'product_url' => 'https://example.test/neomatra-demo/demo-rtx3060-001',
                'stock_status' => 'in_stock',
                'price_type' => 'full_price',
                'shipping_cost' => 25000,
                'service_fee' => 20000,
                'repair_estimate' => 50000,
                'warranty' => 'Garansi toko 30 hari (data demo)',
                'source_published_at' => '2026-08-01T00:00:00+07:00',
            ]],
            'shopee' => [[
                'product_reference' => 'nvidia|rtx 3060|12 gb||nvidia geforce rtx 3060',
                'external_listing_id' => 'demo-rtx3060-002',
                'title' => 'GeForce RTX 3060 12 GB Second - Data Demo',
                'description' => 'Listing portofolio, bukan penawaran nyata.',
                'price' => 3000000,
                'currency' => 'IDR',
                'condition' => 'used',
                'seller_name' => 'Seller Demo Jakarta',
                'seller_type' => 'reseller',
                'location' => 'Jakarta',
                'product_url' => 'https://example.test/neomatra-demo/demo-rtx3060-002',
                'stock_status' => 'in_stock',
                'price_type' => 'full_price',
                'source_published_at' => '2026-07-31T00:00:00+07:00',
            ]],
            'olx' => [[
                'product_reference' => 'nvidia|rtx 3060|12 gb||nvidia geforce rtx 3060',
                'external_listing_id' => 'demo-rtx3060-003',
                'title' => 'NVIDIA RTX 3060 12GB Used - Data Demo',
                'description' => 'Listing portofolio, bukan penawaran nyata.',
                'price' => 3100000,
                'currency' => 'IDR',
                'condition' => 'used',
                'seller_name' => 'Official Demo Store',
                'seller_type' => 'official',
                'location' => 'Bandung',
                'product_url' => 'https://example.test/neomatra-demo/demo-rtx3060-003',
                'stock_status' => 'in_stock',
                'price_type' => 'full_price',
                'source_published_at' => '2026-07-30T00:00:00+07:00',
            ]],
        ],
    ],

    'olx' => [
        'base_url' => env('OLX_BASE_URL', 'https://www.olx.co.id'),
        'search_terms' => array_values(array_filter(array_map(
            static fn (string $term): string => trim($term),
            explode(',', (string) env(
                'OLX_SEARCH_TERMS',
                'rtx 3060 12gb',
            )),
        ))),
        'max_listings' => max(1, min(
            50,
            (int) env('OLX_MAX_LISTINGS', 20),
        )),
        'timeout' => max(1, min(30, (int) env('OLX_HTTP_TIMEOUT', 12))),
        'retries' => max(0, min(2, (int) env('OLX_HTTP_RETRIES', 1))),
        'retry_delay_ms' => max(0, min(
            2000,
            (int) env('OLX_HTTP_RETRY_DELAY_MS', 250),
        )),
        'user_agent' => env(
            'OLX_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                .'AppleWebKit/537.36 (KHTML, like Gecko) '
                .'Chrome/151.0.0.0 Safari/537.36',
        ),
    ],
];
