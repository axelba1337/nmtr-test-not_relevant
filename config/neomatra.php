<?php

$categoryTaxonomy = [
    'pc-dan-komponen' => [
        'name' => 'PC Components',
        'description' => 'Komponen utama komputer desktop.',
        'sort_order' => 10,
        'children' => [
            'gpu' => ['name' => 'GPU', 'sort_order' => 10],
            'cpu' => ['name' => 'CPU', 'sort_order' => 20],
            'ram' => ['name' => 'RAM', 'sort_order' => 30],
            'ssd' => ['name' => 'SSD', 'sort_order' => 40],
            'hdd' => ['name' => 'HDD', 'sort_order' => 50],
            'motherboard' => ['name' => 'Motherboard', 'sort_order' => 60],
            'psu' => ['name' => 'PSU', 'sort_order' => 70],
            'cpu-cooler' => ['name' => 'CPU Cooler', 'sort_order' => 80],
        ],
    ],
    'handphone' => [
        'name' => 'Handphone',
        'description' => 'Perangkat telepon seluler dan smartphone.',
        'sort_order' => 20,
        'children' => [
            'smartphone' => ['name' => 'Smartphone', 'sort_order' => 10],
        ],
    ],
    'laptop' => [
        'name' => 'Laptop',
        'description' => 'Komputer portabel dengan konfigurasi yang teridentifikasi.',
        'sort_order' => 30,
        'children' => [
            'gaming-laptop' => ['name' => 'Gaming Laptop', 'sort_order' => 10],
            'business-laptop' => ['name' => 'Business Laptop', 'sort_order' => 20],
            'consumer-laptop' => ['name' => 'Consumer Laptop', 'sort_order' => 30],
        ],
    ],
    'pc-accessories' => [
        'name' => 'PC Accessories',
        'description' => 'Periferal dan aksesori komputer.',
        'sort_order' => 40,
        'children' => [
            'monitor' => ['name' => 'Monitor', 'sort_order' => 10],
            'keyboard' => ['name' => 'Keyboard', 'sort_order' => 20],
            'mouse' => ['name' => 'Mouse', 'sort_order' => 30],
            'headset' => ['name' => 'Headset', 'sort_order' => 40],
            'webcam' => ['name' => 'Webcam', 'sort_order' => 50],
            'keyboard-mouse-combo' => ['name' => 'Keyboard Mouse Combo', 'sort_order' => 60],
        ],
    ],
    'networking' => [
        'name' => 'Networking',
        'description' => 'Perangkat jaringan rumah dan bisnis kecil.',
        'sort_order' => 50,
        'children' => [
            'router' => ['name' => 'Router', 'sort_order' => 10],
            'access-point' => ['name' => 'Access Point', 'sort_order' => 20],
            'switch' => ['name' => 'Switch', 'sort_order' => 30],
            'modem' => ['name' => 'Modem', 'sort_order' => 40],
            'network-adapter' => ['name' => 'Network Adapter', 'sort_order' => 50],
        ],
    ],
];

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
            'facebook' => ['facebook', 'facebook marketplace', 'fb marketplace'],
            'carousell' => ['carousell'],
        ],
    ],

    'scope' => [
        'supported_category_slugs' => array_keys($categoryTaxonomy),
        'category_taxonomy' => $categoryTaxonomy,
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
            'facebook',
        ],
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
