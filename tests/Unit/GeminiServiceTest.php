<?php

namespace Tests\Unit;

use App\Contracts\AI\ChatClient;
use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\ChatExplanationRequest;
use App\Data\AI\EmbeddingPurpose;
use App\Data\AI\ParsedIntent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GeminiServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.chat.provider' => 'gemini',
            'ai.chat.api_key' => 'chat-unit-key',
            'ai.chat.model' => 'unit-chat-model',
            'ai.chat.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.chat.intent_max_output_tokens' => 321,
            'ai.chat.max_output_tokens' => 123,
            'ai.chat.max_context_chars' => 4000,
            'ai.chat.history.max_messages' => 6,
            'ai.chat.history.max_chars' => 12000,
            'ai.chat.web_grounding.enabled' => true,
            'ai.chat.web_grounding.model' => 'unit-web-model',
            'ai.chat.web_grounding.max_output_tokens' => 333,
            'ai.chat.web_grounding.max_answer_chars' => 2000,
            'ai.chat.web_grounding.max_sources' => 2,
            'ai.embedding.provider' => 'gemini',
            'ai.embedding.api_key' => 'embedding-unit-key',
            'ai.embedding.model' => 'gemini-embedding-2',
            'ai.embedding.dimensions' => 2,
            'ai.embedding.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.http.timeout' => 2,
            'ai.http.retries' => 0,
            'ai.http.retry_delay_ms' => 0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_embedding_adapter_separates_query_and_document_purposes(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => ['values' => [1, 0]]])
                ->push(['embedding' => ['values' => [0, 1]]]),
        ]);

        $client = app(EmbeddingClient::class);
        $document = $client->embed(
            'Produk: RTX 3060',
            EmbeddingPurpose::Product,
            'RTX 3060',
        );
        $query = $client->embed(
            'Cari RTX 3060',
            EmbeddingPurpose::Query,
            'Judul yang tidak boleh dikirim',
        );

        $this->assertTrue($document->succeeded());
        $this->assertSame([1.0, 0.0], $document->vector);
        $this->assertSame('gemini-embedding-2', $document->model);
        $this->assertSame(2, $document->dimensions);
        $this->assertTrue($query->succeeded());
        $this->assertSame([0.0, 1.0], $query->vector);

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        [$documentRequest] = $requests[0];
        [$queryRequest] = $requests[1];

        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent',
            $documentRequest->url(),
        );
        $this->assertTrue($documentRequest->hasHeader(
            'x-goog-api-key',
            'embedding-unit-key',
        ));
        $this->assertSame(
            'models/gemini-embedding-2',
            data_get($documentRequest->data(), 'model'),
        );
        $this->assertArrayNotHasKey(
            'taskType',
            data_get($documentRequest->data(), 'embedContentConfig'),
        );
        $this->assertSame(
            'title: RTX 3060 | text: Produk: RTX 3060',
            data_get($documentRequest->data(), 'content.parts.0.text'),
        );
        $this->assertSame(
            2,
            data_get(
                $documentRequest->data(),
                'embedContentConfig.outputDimensionality',
            ),
        );
        $this->assertSame(
            'task: search result | query: Cari RTX 3060',
            data_get($queryRequest->data(), 'content.parts.0.text'),
        );
        $this->assertArrayNotHasKey(
            'title',
            data_get($queryRequest->data(), 'embedContentConfig'),
        );
    }

    public function test_embedding_adapter_rejects_invalid_vectors_and_returns_safe_failures(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => ['values' => [1, 0, 0]]])
                ->push(['embedding' => ['values' => [0, 0]]])
                ->push([
                    'error' => [
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'private quota detail',
                    ],
                ], 429),
        ]);

        $client = app(EmbeddingClient::class);
        $wrongDimension = $client->embed('one', EmbeddingPurpose::Query);
        $zeroVector = $client->embed('two', EmbeddingPurpose::Knowledge);
        $rateLimited = $client->embed('three', EmbeddingPurpose::Alias);

        $this->assertFalse($wrongDimension->succeeded());
        $this->assertSame('invalid_vector', $wrongDimension->failure?->category);
        $this->assertFalse($zeroVector->succeeded());
        $this->assertSame('invalid_vector', $zeroVector->failure?->category);
        $this->assertFalse($rateLimited->succeeded());
        $this->assertSame(
            'quota_or_rate_limit',
            $rateLimited->failure?->category,
        );
        $this->assertSame(429, $rateLimited->failure?->status);
        $this->assertSame(
            'RESOURCE_EXHAUSTED',
            $rateLimited->failure?->upstreamCode,
        );
        $this->assertStringNotContainsString(
            'private quota detail',
            json_encode($rateLimited->failure?->toSafeArray()),
        );
        Http::assertSentCount(3);
    }

    public function test_embedding_configuration_and_connection_failures_do_not_leak_details(): void
    {
        config(['ai.embedding.api_key' => '']);
        Http::fake();

        $missingConfiguration = app(EmbeddingClient::class)->embed(
            'query',
            EmbeddingPurpose::Query,
        );

        $this->assertFalse($missingConfiguration->succeeded());
        $this->assertSame(
            'configuration',
            $missingConfiguration->failure?->category,
        );
        Http::assertNothingSent();

        config(['ai.embedding.api_key' => 'embedding-unit-key']);
        Http::fake(static function (): never {
            throw new ConnectionException('private connection detail');
        });

        $connection = app(EmbeddingClient::class)->embed(
            'query',
            EmbeddingPurpose::Query,
        );

        $this->assertFalse($connection->succeeded());
        $this->assertSame('connection', $connection->failure?->category);
        $this->assertStringNotContainsString(
            'private connection detail',
            $connection->failure?->safeMessage ?? '',
        );
    }

    public function test_transient_embedding_failure_uses_the_configured_retry_limit(): void
    {
        config([
            'ai.http.retries' => 1,
            'ai.http.retry_delay_ms' => 0,
        ]);
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'error' => ['status' => 'UNAVAILABLE'],
                ], 503)
                ->push(['embedding' => ['values' => [1, 0]]]),
        ]);

        $result = app(EmbeddingClient::class)->embed(
            'query',
            EmbeddingPurpose::Query,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([1.0, 0.0], $result->vector);
        Http::assertSentCount(2);
    }

    public function test_chat_adapter_parses_and_validates_structured_intent(): void
    {
        Http::fake([
            '*' => Http::response($this->textResponse(json_encode([
                'product' => 'RTX 3060',
                'intent_type' => 'product_search',
                'category' => 'GPU',
                'brand' => 'NVIDIA',
                'model' => 'RTX 3060',
                'variant' => '12 GB',
                'sku_or_part_number' => null,
                'budget_min' => null,
                'budget_max' => 2800000,
                'conditions' => ['used'],
                'locations' => ['Surabaya'],
                'needs_clarification' => false,
                'clarification_question' => null,
            ], JSON_THROW_ON_ERROR))),
        ]);

        $result = app(ChatClient::class)->parseIntent(
            'Cari RTX 3060 12 GB bekas di Surabaya maksimal Rp2.800.000',
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame('RTX 3060', $result->intent?->model);
        $this->assertSame('12 GB', $result->intent?->variant);
        $this->assertSame(2800000, $result->intent?->budgetMax);
        $this->assertSame(['used'], $result->intent?->conditions);
        $this->assertArrayNotHasKey('seller_types', $result->intent?->toArray() ?? []);
        $this->assertArrayNotHasKey('mode', $result->intent?->toArray() ?? []);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $system = (string) data_get(
                $payload,
                'systemInstruction.parts.0.text',
            );

            return $request->url()
                    === 'https://generativelanguage.googleapis.com/v1beta/models/unit-chat-model:generateContent'
                && $request->hasHeader('x-goog-api-key', 'chat-unit-key')
                && data_get(
                    $payload,
                    'generationConfig.responseMimeType',
                ) === 'application/json'
                && data_get(
                    $payload,
                    'generationConfig.maxOutputTokens',
                ) === 321
                && ! array_key_exists(
                    'temperature',
                    data_get($payload, 'generationConfig'),
                )
                && ! array_key_exists(
                    'seller_types',
                    data_get($payload, 'generationConfig.responseSchema.properties'),
                )
                && ! array_key_exists(
                    'mode',
                    data_get($payload, 'generationConfig.responseSchema.properties'),
                )
                && ! array_key_exists('tools', $payload)
                && str_contains($system, 'mengekstrak intent')
                && str_contains($system, 'Jangan mencari listing');
        });
    }

    public function test_chat_adapter_rejects_invalid_intent_responses(): void
    {
        $invalidEnum = [
            'product' => 'RTX 3060',
            'category' => null,
            'brand' => null,
            'model' => null,
            'variant' => null,
            'sku_or_part_number' => null,
            'budget_min' => null,
            'budget_max' => null,
            'conditions' => ['definitely_new'],
            'locations' => [],
            'needs_clarification' => false,
            'clarification_question' => null,
        ];
        Http::fake([
            '*' => Http::sequence()
                ->push($this->textResponse('not-json'))
                ->push($this->textResponse(json_encode(
                    $invalidEnum,
                    JSON_THROW_ON_ERROR,
                )))
                ->push([
                    'promptFeedback' => ['blockReason' => 'SAFETY'],
                ]),
        ]);

        $client = app(ChatClient::class);

        foreach (['one', 'two', 'three'] as $message) {
            $result = $client->parseIntent($message);
            $this->assertFalse($result->succeeded());
            $this->assertSame(
                'invalid_response',
                $result->failure?->category,
            );
        }

        Http::assertSentCount(3);
    }

    public function test_chat_adapter_accepts_a_knowledge_intent_without_a_product(): void
    {
        Http::fake([
            '*' => Http::response($this->textResponse(json_encode([
                'product' => '',
                'intent_type' => 'knowledge_question',
                'category' => null,
                'brand' => null,
                'model' => null,
                'variant' => null,
                'sku_or_part_number' => null,
                'budget_min' => null,
                'budget_max' => null,
                'conditions' => [],
                'locations' => [],
                'needs_clarification' => false,
                'clarification_question' => null,
            ], JSON_THROW_ON_ERROR))),
        ]);

        $result = app(ChatClient::class)->parseIntent(
            'Apa yang dapat dilakukan Neomatra?',
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame('knowledge_question', $result->intent?->intentType);
        $this->assertSame('', $result->intent?->product);
    }

    public function test_chat_history_is_bounded_and_general_intent_is_classified(): void
    {
        Http::fake([
            '*' => Http::response($this->textResponse(json_encode([
                'product' => '',
                'intent_type' => 'general_question',
                'category' => null,
                'brand' => null,
                'model' => null,
                'variant' => null,
                'sku_or_part_number' => null,
                'budget_min' => null,
                'budget_max' => null,
                'conditions' => [],
                'locations' => [],
                'needs_clarification' => false,
                'clarification_question' => null,
            ], JSON_THROW_ON_ERROR))),
        ]);
        $history = [
            ['role' => 'user', 'content' => 'dibuang-satu'],
            ['role' => 'assistant', 'content' => 'dibuang-dua'],
            ['role' => 'user', 'content' => 'riwayat-satu'],
            ['role' => 'assistant', 'content' => 'riwayat-dua'],
            ['role' => 'user', 'content' => 'riwayat-tiga'],
            ['role' => 'assistant', 'content' => 'riwayat-empat'],
            ['role' => 'invalid', 'content' => 'abaikan'],
            ['role' => 'user', 'content' => 'riwayat-lima'],
            ['role' => 'assistant', 'content' => 'riwayat-enam'],
        ];

        $result = app(ChatClient::class)->parseIntent(
            'Siapa presiden Indonesia saat ini?',
            $history,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame('general_question', $result->intent?->intentType);
        Http::assertSent(function (Request $request): bool {
            $contents = data_get($request->data(), 'contents');
            $system = (string) data_get(
                $request->data(),
                'systemInstruction.parts.0.text',
            );

            return is_array($contents)
                && count($contents) === 1
                && data_get($contents, '0.role') === 'user'
                && str_contains(
                    (string) data_get($contents, '0.parts.0.text'),
                    'RIWAYAT_PERCAKAPAN_TIDAK_TERPERCAYA_JSON',
                )
                && str_contains(
                    (string) data_get($contents, '0.parts.0.text'),
                    'riwayat-satu',
                )
                && ! str_contains(
                    (string) data_get($contents, '0.parts.0.text'),
                    'dibuang-satu',
                )
                && str_contains(
                    (string) data_get($contents, '0.parts.0.text'),
                    '"role":"assistant"',
                )
                && str_contains(
                    (string) data_get($contents, '0.parts.0.text'),
                    'presiden Indonesia',
                )
                && str_contains($system, 'general_question')
                && str_contains($system, 'tidak tepercaya')
                && str_contains($system, 'Hanya product_search');
        });
    }

    public function test_chat_explanation_uses_backend_facts_without_web_tools_or_recalculation(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => '{"answer":"Listing ini '],
                        ['text' => '13,5% di bawah pasar.","supported_by_context":true}'],
                    ]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 20,
                    'candidatesTokenCount' => 8,
                    'totalTokenCount' => 28,
                ],
                'modelVersion' => 'unit-chat-model-001',
            ]),
        ]);

        $intent = $this->intent();
        $result = app(ChatClient::class)->explain(
            new ChatExplanationRequest(
                'Mengapa listing ini menarik?',
                $intent,
                [[
                    'title' => 'RTX 3060 12GB Data Demo',
                    'item_price' => 2500000,
                    'market' => ['price' => 3000000],
                    'costs' => ['total' => 2595000],
                    'undervalued' => ['percentage' => 13.5],
                ]],
                [['title' => 'Disclaimer', 'content' => 'Estimasi saja.']],
            ),
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(
            'Listing ini 13,5% di bawah pasar.',
            $result->text,
        );
        $this->assertSame(28, $result->usage['total_tokens']);
        $this->assertSame('unit-chat-model-001', $result->modelVersion);
        $this->assertTrue($result->supportedByContext);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $system = (string) data_get(
                $payload,
                'systemInstruction.parts.0.text',
            );
            $prompt = (string) data_get(
                $payload,
                'contents.0.parts.0.text',
            );

            return str_contains($system, 'Jangan mengubah')
                && str_contains($system, 'dihitung backend')
                && str_contains($prompt, 'DATA_BACKEND_TERPERCAYA')
                && str_contains($prompt, '2595000')
                && str_contains($prompt, '13.5')
                && data_get(
                    $payload,
                    'generationConfig.responseMimeType',
                ) === 'application/json'
                && data_get(
                    $payload,
                    'generationConfig.responseSchema.properties.supported_by_context.type',
                ) === 'boolean'
                && ! array_key_exists('tools', $payload)
                && ! array_key_exists(
                    'temperature',
                    data_get($payload, 'generationConfig'),
                );
        });
    }

    public function test_chat_provider_failure_is_safe_and_does_not_expose_raw_body(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'status' => 'RESOURCE_EXHAUSTED',
                    'message' => 'private provider quota message',
                ],
            ], 429),
        ]);

        $result = app(ChatClient::class)->explain(
            new ChatExplanationRequest('Question', $this->intent(), []),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('quota_or_rate_limit', $result->failure?->category);
        $this->assertSame(429, $result->failure?->status);
        $this->assertStringNotContainsString(
            'private provider quota message',
            json_encode($result->failure?->toSafeArray()),
        );
    }

    public function test_web_grounding_uses_a_separate_model_and_returns_safe_bounded_sources(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => 'Jawaban terbaru '],
                        ['text' => 'yang terverifikasi.'],
                    ]],
                    'finishReason' => 'STOP',
                    'groundingMetadata' => [
                        'searchEntryPoint' => [
                            'renderedContent' => ' <div class="search-suggestions">Google Search</div> ',
                        ],
                        'groundingChunks' => [
                            ['web' => [
                                'uri' => 'https://news.example.test/article#bagian',
                                'title' => ' News Utama ',
                            ]],
                            ['web' => [
                                'uri' => 'https://news.example.test/article#duplikat',
                                'title' => 'Duplikat',
                            ]],
                            ['web' => [
                                'uri' => 'javascript:alert(1)',
                                'title' => 'Tidak aman',
                            ]],
                            ['web' => [
                                'uri' => 'https://user:pass@example.test/private',
                                'title' => 'Kredensial',
                            ]],
                            ['web' => [
                                'uri' => 'https://docs.example.test/latest',
                                'title' => "Official\nUpdate",
                            ]],
                            ['web' => [
                                'uri' => 'https://third.example.test/ignored',
                                'title' => 'Melebihi batas',
                            ]],
                        ],
                    ],
                ]],
                'usageMetadata' => [
                    'promptTokenCount' => 30,
                    'candidatesTokenCount' => 10,
                    'totalTokenCount' => 40,
                ],
                'modelVersion' => 'unit-web-model-001',
            ]),
        ]);
        $request = new ChatExplanationRequest(
            'Apa kabar teknologi terbaru?',
            $this->intent(),
            [],
            [],
            [
                ['role' => 'user', 'content' => 'Konteks sebelumnya'],
                ['role' => 'assistant', 'content' => 'Jawaban sebelumnya'],
            ],
        );

        $result = app(ChatClient::class)->explainWithWebGrounding($request);

        $this->assertTrue($result->succeeded());
        $this->assertSame(
            'Jawaban terbaru yang terverifikasi.',
            $result->text,
        );
        $this->assertTrue($result->supportedByContext);
        $this->assertSame('unit-web-model-001', $result->modelVersion);
        $this->assertSame(40, $result->usage['total_tokens']);
        $this->assertSame(
            '<div class="search-suggestions">Google Search</div>',
            $result->searchEntryPointHtml,
        );
        $this->assertSame([
            [
                'kind' => 'web',
                'title' => 'News Utama',
                'publisher' => 'news.example.test',
                'url' => 'https://news.example.test/article',
            ],
            [
                'kind' => 'web',
                'title' => 'Official Update',
                'publisher' => 'docs.example.test',
                'url' => 'https://docs.example.test/latest',
            ],
        ], $result->sources);
        Http::assertSent(function (Request $httpRequest): bool {
            $payload = $httpRequest->data();
            $tool = data_get($payload, 'tools.0');
            $generationConfig = data_get($payload, 'generationConfig');

            return $httpRequest->url()
                    === 'https://generativelanguage.googleapis.com/v1beta/models/unit-web-model:generateContent'
                && is_array($tool)
                && array_key_exists('googleSearch', $tool)
                && data_get($payload, 'contents.0.role') === 'user'
                && count((array) data_get($payload, 'contents')) === 1
                && str_contains(
                    (string) data_get($payload, 'contents.0.parts.0.text'),
                    '"role":"assistant"',
                )
                && str_contains(
                    (string) data_get($payload, 'contents.0.parts.0.text'),
                    'DATA_BACKEND_TERPERCAYA',
                )
                && data_get(
                    $generationConfig,
                    'maxOutputTokens',
                ) === 333
                && ! array_key_exists('responseMimeType', $generationConfig)
                && ! array_key_exists('responseSchema', $generationConfig);
        });
    }

    public function test_web_grounding_fails_safely_when_disabled_or_missing_sources(): void
    {
        config(['ai.chat.web_grounding.enabled' => false]);

        $disabled = app(ChatClient::class)->explainWithWebGrounding(
            new ChatExplanationRequest('Question', $this->intent(), []),
        );

        $this->assertFalse($disabled->succeeded());
        $this->assertSame('configuration', $disabled->failure?->category);
        $this->assertSame('unit-web-model', $disabled->failure?->model);
        Http::assertNothingSent();

        config(['ai.chat.web_grounding.enabled' => true]);
        Http::fake([
            '*' => Http::response($this->textResponse(
                'Jawaban tanpa metadata grounding.',
            )),
        ]);

        $ungrounded = app(ChatClient::class)->explainWithWebGrounding(
            new ChatExplanationRequest('Question', $this->intent(), []),
        );

        $this->assertFalse($ungrounded->succeeded());
        $this->assertSame(
            'grounding_unavailable',
            $ungrounded->failure?->category,
        );
        $this->assertSame([], $ungrounded->sources);
        $this->assertNull($ungrounded->searchEntryPointHtml);
        Http::assertSentCount(1);
    }

    public function test_web_grounding_requires_a_safe_search_entry_point(): void
    {
        foreach ([" \n\t ", str_repeat('x', 20001)] as $renderedContent) {
            Http::fake([
                '*' => Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [['text' => 'Jawaban grounded.']],
                        ],
                        'finishReason' => 'STOP',
                        'groundingMetadata' => [
                            'searchEntryPoint' => [
                                'renderedContent' => $renderedContent,
                            ],
                            'groundingChunks' => [[
                                'web' => [
                                    'uri' => 'https://example.test/source',
                                    'title' => 'Sumber',
                                ],
                            ]],
                        ],
                    ]],
                ]),
            ]);

            $result = app(ChatClient::class)->explainWithWebGrounding(
                new ChatExplanationRequest(
                    'Question',
                    $this->intent(),
                    [],
                ),
            );

            $this->assertFalse($result->succeeded());
            $this->assertSame(
                'grounding_unavailable',
                $result->failure?->category,
            );
            $this->assertNull($result->searchEntryPointHtml);
        }
    }

    public function test_oversized_backend_context_is_rejected_before_http(): void
    {
        config(['ai.chat.max_context_chars' => 1000]);
        Http::fake();

        $result = app(ChatClient::class)->explain(
            new ChatExplanationRequest(
                'Question',
                $this->intent(),
                [['description' => str_repeat('x', 5000)]],
            ),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('invalid_context', $result->failure?->category);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function textResponse(string $text): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]]],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    private function intent(): ParsedIntent
    {
        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060',
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB',
            'sku_or_part_number' => null,
            'budget_min' => null,
            'budget_max' => 2800000,
            'conditions' => ['used'],
            'locations' => ['Surabaya'],
            'needs_clarification' => false,
            'clarification_question' => null,
        ]);

        $this->assertInstanceOf(ParsedIntent::class, $intent);

        return $intent;
    }
}
