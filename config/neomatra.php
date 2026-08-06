<?php

return [
    'currency' => env('NEOMATRA_DEFAULT_CURRENCY', 'IDR'),

    'search' => [
        // Semantic candidates are ranked before the SRS attribute gate. There is
        // intentionally no numeric similarity threshold in this configuration.
        'product_candidate_k' => env('NEOMATRA_PRODUCT_CANDIDATE_K', 20),
        'result_top_k' => env('NEOMATRA_RESULT_TOP_K', 5),
        'knowledge_top_k' => env('NEOMATRA_KNOWLEDGE_TOP_K', 3),
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
