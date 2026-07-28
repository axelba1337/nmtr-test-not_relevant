<?php

return [
    'models' => [
        'chat' => env('GEMINI_CHAT_MODEL', 'gemini-3.5-flash-lite'),
        'embed' => env('GEMINI_EMBED_MODEL', 'gemini-embedding-2'),
    ],

    'embedding_dimensions' => (int) env('GEMINI_EMBED_DIMENSIONS', 768),

    'top_k' => max(1, min(10, (int) env('CHATBOT_TOP_K', 3))),

    'max_context_chars' => max(1, (int) env('CHATBOT_MAX_CONTEXT_CHARS', 4000)),

    'max_output_tokens' => max(1, (int) env('CHATBOT_MAX_OUTPUT_TOKENS', 512)),

    'http' => [
        'timeout' => max(1, (int) env('CHATBOT_HTTP_TIMEOUT', 25)),
        'retry' => max(0, (int) env('CHATBOT_HTTP_RETRY', 1)),
    ],

    'rate_limit' => max(1, (int) env('CHATBOT_RATE_LIMIT', 20)),
];
