<?php

$legacyGeminiKey = env('GEMINI_API_KEY');
$chatApiKey = env('CHAT_API_KEY');
$embeddingApiKey = env('EMBEDDING_API_KEY');

if (trim((string) $chatApiKey) === '') {
    $chatApiKey = $legacyGeminiKey;
}

if (trim((string) $embeddingApiKey) === '') {
    $embeddingApiKey = $legacyGeminiKey;
}

return [
    'chat' => [
        'provider' => env('CHAT_PROVIDER', 'gemini'),
        'api_key' => $chatApiKey,
        'model' => env(
            'CHAT_MODEL',
            env('GEMINI_CHAT_MODEL', 'gemini-3.1-flash-lite'),
        ),
        'base_url' => env(
            'CHAT_API_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta',
        ),
        'intent_max_output_tokens' => max(
            1,
            (int) env('CHAT_INTENT_MAX_OUTPUT_TOKENS', 512),
        ),
        'max_output_tokens' => max(
            1,
            (int) env('CHAT_MAX_OUTPUT_TOKENS', 512),
        ),
        'max_context_chars' => max(
            1000,
            (int) env('CHAT_MAX_CONTEXT_CHARS', 12000),
        ),
        'history' => [
            'max_messages' => min(
                6,
                max(0, (int) env('CHAT_HISTORY_MAX_MESSAGES', 6)),
            ),
            'max_chars' => min(
                24000,
                max(1000, (int) env('CHAT_HISTORY_MAX_CHARS', 12000)),
            ),
        ],
        'web_grounding' => [
            'enabled' => filter_var(
                env('CHAT_WEB_GROUNDING_ENABLED', true),
                FILTER_VALIDATE_BOOL,
            ),
            'model' => env('CHAT_WEB_MODEL', 'gemini-2.5-flash'),
            'max_output_tokens' => min(
                2048,
                max(1, (int) env(
                    'CHAT_WEB_MAX_OUTPUT_TOKENS',
                    1024,
                )),
            ),
            'max_answer_chars' => min(
                16000,
                max(500, (int) env('CHAT_WEB_MAX_ANSWER_CHARS', 8000)),
            ),
            'max_sources' => min(
                8,
                max(1, (int) env('CHAT_WEB_MAX_SOURCES', 5)),
            ),
        ],
    ],

    'embedding' => [
        'provider' => env('EMBEDDING_PROVIDER', 'gemini'),
        'api_key' => $embeddingApiKey,
        'model' => env(
            'EMBEDDING_MODEL',
            env('GEMINI_EMBED_MODEL', 'gemini-embedding-2'),
        ),
        'dimensions' => max(
            1,
            (int) env(
                'EMBEDDING_DIMENSIONS',
                env('GEMINI_EMBED_DIMENSIONS', 768),
            ),
        ),
        'base_url' => env(
            'EMBEDDING_API_BASE_URL',
            'https://generativelanguage.googleapis.com/v1beta',
        ),
    ],

    'http' => [
        'timeout' => max(1, (int) env('AI_HTTP_TIMEOUT', 25)),
        'retries' => min(5, max(0, (int) env('AI_HTTP_RETRIES', 2))),
        'retry_delay_ms' => min(5000, max(
            0,
            (int) env('AI_HTTP_RETRY_DELAY_MS', 1000),
        )),
    ],
];
