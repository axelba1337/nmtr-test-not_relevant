<?php

return [
    'rate_limit' => max(1, (int) env('CHATBOT_RATE_LIMIT', 20)),
    'search' => [
        'soft_timeout_seconds' => max(
            1,
            (int) env('CHATBOT_SOFT_TIMEOUT_SECONDS', 20),
        ),
        'hard_timeout_seconds' => max(
            1,
            (int) env('CHATBOT_HARD_TIMEOUT_SECONDS', 30),
        ),
    ],
];
