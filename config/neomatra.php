<?php

return [
    'currency' => env('NEOMATRA_DEFAULT_CURRENCY', 'IDR'),

    'search' => [
        // Semantic candidates are ranked before the SRS attribute gate. There is
        // intentionally no numeric similarity threshold in this configuration.
        'product_candidate_k' => env('NEOMATRA_PRODUCT_CANDIDATE_K', 20),
        'result_top_k' => env('NEOMATRA_RESULT_TOP_K', 5),
        'knowledge_top_k' => env('NEOMATRA_KNOWLEDGE_TOP_K', 3),
        'known_locations' => [
            'Jakarta', 'Bandung', 'Surabaya', 'Semarang', 'Yogyakarta',
            'Medan', 'Makassar', 'Depok', 'Bekasi', 'Tangerang', 'Malang',
            'Denpasar', 'Palembang', 'Bogor', 'Solo',
        ],
        'platform_aliases' => [
            'tokopedia' => ['tokopedia', 'tokped'],
            'shopee' => ['shopee'],
            'olx' => ['olx', 'tokobagus', 'toko bagus'],
            'facebook-marketplace' => ['facebook marketplace', 'fb marketplace'],
            'carousell' => ['carousell'],
            'blibli' => ['blibli'],
            'lazada' => ['lazada'],
        ],
    ],

    'scope' => [
        'supported_category_slugs' => [
            'pc-dan-komponen',
            'handphone',
            'laptop',
            'pc-accessories',
            'networking',
        ],
        'unsupported_keywords' => [
            'mobil', 'motor', 'kendaraan', 'vehicle', 'car', 'motorcycle',
            'honda civic', 'honda jazz', 'toyota', 'suzuki', 'mitsubishi',
            'properti', 'rumah', 'apartemen', 'tanah', 'property', 'house',
            'fashion', 'baju', 'sepatu', 'shoes', 'food', 'makanan',
            'furniture', 'furnitur', 'travel', 'tiket', 'ticket',
            'lowongan kerja', 'job vacancy', 'tiket pesawat', 'hotel',
        ],
        'invalid_tokens' => [
            'asdf', 'asdfgh', 'qwerty', 'qwertyuiop', 'testtest', '123456',
        ],
        'external_handoff_platform_slugs' => [
            'olx',
            'facebook-marketplace',
        ],
    ],

    'pricing' => [
        'minimum_comparables' => env('NEOMATRA_MINIMUM_COMPARABLES', 2),
    ],

    'freshness' => [
        'stale_after_hours' => env('NEOMATRA_LISTING_STALE_AFTER_HOURS', 168),
    ],

    'privacy' => [
        'unanswered_retention_days' => env(
            'NEOMATRA_UNANSWERED_RETENTION_DAYS',
            30,
        ),
    ],
];
