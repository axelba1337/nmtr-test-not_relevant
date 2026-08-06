<?php

$targets = array_values(array_filter(array_map(
    static fn (string $target): string => strtolower(trim($target)),
    explode(',', (string) env(
        'MARKETPLACE_TARGETS',
        'tokopedia,shopee,olx',
    )),
)));

return [
    'provider' => env('MARKETPLACE_PROVIDER', 'demo'),
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
     * The demo adapter intentionally performs no network request. Seeders or
     * tests may provide normalized records through this config key.
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
];
