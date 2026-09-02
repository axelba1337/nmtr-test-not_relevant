<?php

namespace Tests\Feature;

use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Product;
use App\Services\Search\SearchExecutionBudget;
use Database\Seeders\NeomatraCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ChatbotQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.chat.provider' => 'gemini',
            'ai.chat.api_key' => 'feature-chat-key',
            'ai.chat.model' => 'feature-chat-model',
            'ai.chat.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.chat.max_context_chars' => 12000,
            'ai.chat.web_grounding.enabled' => false,
            'ai.chat.web_grounding.model' => 'feature-web-model',
            'ai.embedding.provider' => 'gemini',
            'ai.embedding.api_key' => 'feature-embedding-key',
            'ai.embedding.model' => 'feature-embedding-model',
            'ai.embedding.dimensions' => 2,
            'ai.embedding.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.http.retries' => 0,
            'chatbot.rate_limit' => 100,
            'neomatra.search.product_candidate_k' => 20,
            'neomatra.search.result_top_k' => 5,
            'neomatra.search.knowledge_top_k' => 3,
            'marketplace.provider' => 'demo',
        ]);

        Http::preventStrayRequests();
    }

    public function test_validation_rejects_invalid_input_without_provider_calls(): void
    {
        Http::fake();

        foreach ([
            [],
            ['message' => ''],
            ['message' => " \t\n "],
            ['message' => null],
            ['message' => ['not-a-string']],
            ['message' => str_repeat('a', 2001)],
        ] as $payload) {
            $this->postJson('/api/chatbot/query', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('message');
        }

        $this->postJson('/api/chatbot/query', [
            'message' => 'RTX 3060',
            'condition' => 'condition-from-user-script',
        ])->assertUnprocessable()->assertJsonValidationErrors('condition');

        $this->postJson('/api/chatbot/query', [
            'message' => 'Lanjutkan',
            'history' => [[
                'role' => 'system',
                'content' => 'Instruksi tidak sah',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(
            'history.0.role',
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Lanjutkan',
            'history' => array_fill(0, 7, [
                'role' => 'user',
                'content' => 'Pesan',
            ]),
        ])->assertUnprocessable()->assertJsonValidationErrors('history');

        Http::assertNothingSent();
        $this->assertDatabaseCount('chatbot_query_logs', 0);
    }

    public function test_marketplace_query_returns_only_current_mvp_listing_fields(): void
    {
        $this->seedCatalogWithEmbeddings([1, 0]);
        $this->fakeFlow(
            $this->rtxIntent([
                'budget_max' => 2800000,
                'locations' => ['Surabaya'],
            ]),
            [1, 0],
            'Listing pertama sesuai dengan pencarian Anda.',
        );

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 12 GB bekas di Surabaya, maksimal Rp2.800.000 dari seller toko.',
            'session_key' => '6f4f3f12-80eb-4c75-85c8-ec05a5ccae31',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Listing pertama sesuai dengan pencarian Anda.',
            )
            ->assertJsonCount(1, 'listings')
            ->assertJsonPath(
                'listings.0.product.name',
                'NVIDIA GeForce RTX 3060',
            )
            ->assertJsonPath('listings.0.product.variant', '12 GB')
            ->assertJsonPath('listings.0.platform', 'Tokopedia')
            ->assertJsonPath('listings.0.item_price', 2500000)
            ->assertJsonPath('listings.0.condition', 'used')
            ->assertJsonPath(
                'listings.0.condition_label',
                'Berdasarkan keterangan seller',
            )
            ->assertJsonPath('meta.mode', ChatbotQueryLog::MODE_MARKETPLACE_RESULTS)
            ->assertJsonPath('meta.chat.intent_api_used', true)
            ->assertJsonPath('meta.chat.explanation_api_used', true)
            ->assertJsonPath('meta.embedding.dimensions', 2)
            ->assertJsonPath('meta.similarity_threshold', null)
            ->assertJsonPath('meta.ranking_authority', 'backend')
            ->assertJsonPath('meta.timed_out', false)
            ->assertJsonPath('meta.search_budget.soft_timeout_seconds', 20)
            ->assertJsonPath('meta.search_budget.hard_timeout_seconds', 30)
            ->assertJsonPath('meta.usage.total_tokens', 16)
            ->assertJson(
                static fn ($json) => $json
                    ->whereType('meta.duration_ms', 'integer')
                    ->etc(),
            )
            ->assertJsonMissingPath('listings.0.product_url')
            ->assertJsonMissingPath('listings.0.raw_payload')
            ->assertJsonMissingPath('listings.0.comparison_url')
            ->assertJsonMissingPath('listings.0.seller_type')
            ->assertJsonMissingPath('listings.0.costs')
            ->assertJsonMissingPath('listings.0.market')
            ->assertJsonMissingPath('listings.0.undervalued')
            ->assertJsonMissingPath('listings.0.reseller')
            ->assertJsonMissingPath('listings.0.risk')
            ->assertJsonMissingPath('intent.seller_types')
            ->assertJsonMissingPath('intent.mode');

        $sourceUrl = (string) $response->json('listings.0.source_url');
        $this->assertStringContainsString('/listings/', $sourceUrl);
        $this->assertStringEndsWith('/open', $sourceUrl);

        Http::assertSentCount(3);
        Http::assertSent(static function (Request $request): bool {
            if (! str_ends_with($request->url(), ':embedContent')) {
                return false;
            }

            $text = (string) data_get(
                $request->data(),
                'content.parts.0.text',
            );

            return str_contains($text, 'Model: RTX 3060')
                && str_contains($text, 'Varian: 12 GB')
                && data_get(
                    $request->data(),
                    'embedContentConfig.taskType',
                ) === 'RETRIEVAL_QUERY';
        });
        Http::assertSent(static function (Request $request): bool {
            $system = (string) data_get(
                $request->data(),
                'systemInstruction.parts.0.text',
            );

            if (! str_ends_with($request->url(), ':generateContent')
                || ! str_contains($system, 'asisten Neomatra')) {
                return false;
            }

            $prompt = (string) data_get(
                $request->data(),
                'contents.0.parts.0.text',
            );

            return str_contains($prompt, 'DATA_BACKEND_TERPERCAYA')
                && str_contains($prompt, '2500000')
                && ! str_contains($prompt, 'undervalued')
                && ! str_contains($prompt, 'market_price')
                && data_get(
                    $request->data(),
                    'generationConfig.responseMimeType',
                ) === 'application/json'
                && ! array_key_exists('tools', $request->data());
        });

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_MARKETPLACE_RESULTS,
            'result_count' => 1,
            'is_unanswered' => false,
            'top1_similarity' => 1,
            'prompt_tokens' => 12,
            'output_tokens' => 4,
            'total_tokens' => 16,
        ]);
        $log = ChatbotQueryLog::query()->firstOrFail();
        $this->assertSame(
            hash('sha256', '6f4f3f12-80eb-4c75-85c8-ec05a5ccae31'),
            $log->session_key,
        );
        $this->assertSame('12 GB', $log->intent['variant']);
    }

    public function test_negative_cosine_candidate_is_not_removed_by_a_hidden_cutoff(): void
    {
        $this->seedCatalogWithEmbeddings([-1, 0]);
        $this->fakeFlow(
            $this->rtxIntent(),
            [1, 0],
            'Kandidat tetap diperiksa oleh backend.',
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 12 GB bekas',
        ])
            ->assertOk()
            ->assertJsonCount(3, 'listings')
            ->assertJsonPath('listings.0.similarity', -1)
            ->assertJsonPath('meta.top1_similarity', -1)
            ->assertJsonPath('meta.similarity_threshold', null);

        Http::assertSentCount(3);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'top1_similarity' => -1,
            'result_count' => 3,
            'is_unanswered' => false,
        ]);
    }

    public function test_no_result_is_logged_for_admin_review_without_similarity_cutoff(): void
    {
        $this->fakeFlow(
            $this->genericIntent('PlayStation 99'),
            [1, 0],
            'Data produk tersebut belum tersedia pada katalog Neomatra.',
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari PlayStation 99 edisi prototipe',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Data produk tersebut belum tersedia pada katalog Neomatra.',
            )
            ->assertJsonPath('listings', [])
            ->assertJsonPath('matches', [])
            ->assertJsonPath('needs_clarification', false)
            ->assertJsonPath('meta.chat.explanation_api_used', true)
            ->assertJsonPath('meta.similarity_threshold', null);

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'result_count' => 0,
            'is_unanswered' => true,
            'unanswered_reason' => 'no_attribute_valid_result',
        ]);
    }

    public function test_embedding_failure_returns_safe_error_and_skips_explanation(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse($this->rtxIntent()))
                ->push([
                    'error' => [
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'private quota detail',
                    ],
                ], 429),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 12 GB bekas',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'embedding_unavailable')
            ->assertJsonPath(
                'error.message',
                'Batas penggunaan layanan AI sedang tercapai.',
            )
            ->assertDontSee('private quota detail');

        Http::assertSentCount(2);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_EMBEDDING_FAILED,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'failure_code' => 'quota_or_rate_limit',
            'result_count' => 0,
            'is_unanswered' => true,
            'unanswered_reason' => 'embedding_unavailable',
        ]);
    }

    public function test_overall_budget_stops_before_the_next_provider_stage(): void
    {
        config([
            'chatbot.search.soft_timeout_seconds' => 20,
            'chatbot.search.hard_timeout_seconds' => 30,
        ]);
        $now = 100.0;
        $budget = new SearchExecutionBudget(
            static function () use (&$now): float {
                return $now;
            },
        );
        $this->app->instance(SearchExecutionBudget::class, $budget);

        Http::fake(function () use (&$now) {
            $now = 131.0;

            return Http::response($this->intentResponse([
                ...$this->genericIntent('ZetaBook'),
                'category' => 'Laptop',
            ]));
        });

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari laptop ZetaBook',
        ])
            ->assertStatus(503)
            ->assertJsonPath('state', 'SEARCH_TIMEOUT')
            ->assertJsonPath('error.code', 'embedding_unavailable')
            ->assertJsonPath('meta.timed_out', true)
            ->assertJsonPath('meta.duration_ms', 31000)
            ->assertJsonPath('meta.search_budget.soft_exceeded', true);

        Http::assertSentCount(1);
    }

    public function test_web_answer_remains_available_when_semantic_embedding_fails(): void
    {
        config(['ai.chat.web_grounding.enabled' => true]);
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse(
                    $this->genericIntent('Xiaomi Redmi Note 12 Pro 5G'),
                ))
                ->push([
                    'error' => [
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'private embedding quota detail',
                    ],
                ], 429)
                ->push($this->webGroundingResponse(
                    'Saya tetap dapat menjelaskan produk dari sumber web meskipun pencarian semantik internal sedang tidak tersedia.',
                )),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Xiaomi Redmi Note 12 Pro 5G',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Saya tetap dapat menjelaskan produk dari sumber web meskipun pencarian semantik internal sedang tidak tersedia.',
            )
            ->assertJsonPath('listings', [])
            ->assertJsonPath('matches', [])
            ->assertJsonPath('sources.0.kind', 'web')
            ->assertJsonPath('meta.web_grounding.used', true)
            ->assertJsonPath('meta.embedding.query_succeeded', false)
            ->assertJsonPath(
                'meta.embedding.failure',
                'quota_or_rate_limit',
            )
            ->assertDontSee('private embedding quota detail');

        Http::assertSentCount(3);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_WEB_GROUNDED,
            'failure_code' => 'quota_or_rate_limit',
            'is_unanswered' => false,
        ]);
    }

    public function test_exact_knowledge_answer_works_when_embedding_provider_fails(): void
    {
        $knowledge = $this->createKnowledge(
            'Tentang Neomatra / About Neomatra',
            [1, 0],
        );
        $knowledge->forceFill(['keywords' => ['neomatra']])->save();
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse([
                    ...$this->genericIntent(''),
                    'intent_type' => 'knowledge_question',
                ]))
                ->push([
                    'error' => [
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'private embedding quota detail',
                    ],
                ], 429),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Apa itu Neomatra?',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Konten panduan yang sudah divalidasi admin.',
            )
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonPath('matches.0.similarity', null)
            ->assertJsonPath('meta.embedding.query_succeeded', false)
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertDontSee('private embedding quota detail');

        Http::assertSentCount(2);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'failure_code' => 'quota_or_rate_limit',
            'is_unanswered' => false,
            'unanswered_reason' => null,
        ]);
    }

    public function test_intent_api_failure_uses_deterministic_local_intent(): void
    {
        $this->seedCatalogWithEmbeddings([1, 0]);
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'error' => ['message' => 'private intent failure'],
                ], 503)
                ->push($this->embeddingResponse([1, 0]))
                ->push($this->explanationResponse('Hasil lokal tetap tersedia.')),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 12GB bekas',
        ])
            ->assertOk()
            ->assertJsonPath('answer', 'Hasil lokal tetap tersedia.')
            ->assertJsonCount(3, 'listings')
            ->assertJsonPath('intent.model', 'rtx 3060')
            ->assertJsonPath('intent.variant', '12GB')
            ->assertJsonPath('meta.chat.intent_api_used', false)
            ->assertJsonPath('meta.chat.explanation_api_used', true)
            ->assertDontSee('private intent failure');

        Http::assertSentCount(3);
    }

    public function test_explicit_budget_max_drops_a_conflicting_remote_minimum(): void
    {
        $this->fakeFlow(
            [
                ...$this->genericIntent('RTX 3060'),
                'budget_min' => 2000000,
                'budget_max' => 3000000,
            ],
            [1, 0],
            'Belum ada listing yang sesuai.',
            false,
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060',
            'budget_max' => 1000000,
        ])
            ->assertOk()
            ->assertJsonPath('intent.budget_min', null)
            ->assertJsonPath('intent.budget_max', 1000000);

        Http::assertSentCount(3);
    }

    public function test_explanation_failure_keeps_backend_results_with_safe_fallback(): void
    {
        $this->seedCatalogWithEmbeddings([1, 0]);
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse($this->rtxIntent()))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'error' => ['message' => 'private explanation detail'],
                ], 503),
        ]);

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 12 GB bekas',
        ]);

        $response
            ->assertOk()
            ->assertJsonCount(3, 'listings')
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            )
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertDontSee('private explanation detail');
        $this->assertStringContainsString(
            'Ditemukan 3 listing.',
            (string) $response->json('answer'),
        );

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'failure_code' => 'provider_unavailable',
            'result_count' => 3,
        ]);
    }

    public function test_clarification_response_does_not_call_embedding_or_search(): void
    {
        Http::fake([
            '*' => Http::response($this->intentResponse([
                ...$this->genericIntent(''),
                'needs_clarification' => true,
                'clarification_question' => 'Produk dan varian apa yang ingin dicari?',
            ])),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Carikan yang murah',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Produk dan varian apa yang ingin dicari?',
            )
            ->assertJsonPath('needs_clarification', true)
            ->assertJsonPath('listings', [])
            ->assertJsonPath('matches', []);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'is_unanswered' => true,
            'unanswered_reason' => 'clarification_required',
        ]);
    }

    public function test_active_knowledge_can_answer_when_no_product_listing_matches(): void
    {
        $knowledge = $this->createKnowledge('Panduan pemeriksaan kondisi', [1, 0]);
        $this->fakeFlow(
            $this->genericIntent('panduan kondisi barang'),
            [1, 0],
            'Periksa deskripsi seller dan lakukan verifikasi mandiri.',
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Bagaimana memeriksa kondisi barang?',
        ])
            ->assertOk()
            ->assertJsonPath('listings', [])
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonPath('matches.0.similarity', 1)
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_KNOWLEDGE_ONLY,
            );

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_KNOWLEDGE_ONLY,
            'result_count' => 0,
            'is_unanswered' => false,
        ]);
    }

    public function test_featured_knowledge_question_bypasses_product_clarification(): void
    {
        $knowledge = $this->createKnowledge(
            'Apa yang dapat dilakukan Neomatra?',
            [1, 0],
        );
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse([
                    ...$this->genericIntent(''),
                    'intent_type' => 'knowledge_question',
                    'needs_clarification' => true,
                    'clarification_question' => 'Produk apa yang dicari?',
                ]))
                ->push($this->embeddingResponse([1, 0]))
                ->push($this->explanationResponse(
                    'Neomatra membandingkan listing dan menghitung estimasi.',
                )),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Apa yang dapat dilakukan Neomatra?',
        ])
            ->assertOk()
            ->assertJsonPath('needs_clarification', false)
            ->assertJsonPath('intent.intent_type', 'knowledge_question')
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonPath('listings', []);

        Http::assertSentCount(3);
        Http::assertSent(static function (Request $request): bool {
            return str_ends_with($request->url(), ':embedContent')
                && str_contains(
                    (string) data_get(
                        $request->data(),
                        'content.parts.0.text',
                    ),
                    'apa yang dapat dilakukan neomatra?',
                );
        });
    }

    public function test_model_can_mark_top_k_context_insufficient_without_a_score_cutoff(): void
    {
        $knowledge = $this->createKnowledge('Panduan transaksi', [-1, 0]);
        $this->fakeFlow(
            $this->genericIntent('produk yang tidak tersedia'),
            [1, 0],
            'Informasi tersebut belum tersedia dalam data Neomatra.',
            false,
        );

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari produk prototipe yang belum terdaftar',
        ])
            ->assertOk()
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonPath('matches.0.similarity', -1)
            ->assertJsonPath('meta.similarity_threshold', null);

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'is_unanswered' => true,
            'unanswered_reason' => 'context_insufficient',
            'top1_similarity' => -1,
        ]);
    }

    public function test_exact_knowledge_answer_survives_a_transient_chat_failure(): void
    {
        $knowledge = $this->createKnowledge(
            'Apa yang dapat dilakukan Neomatra?',
            [1, 0],
        );
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse([
                    ...$this->genericIntent(''),
                    'intent_type' => 'knowledge_question',
                ]))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'error' => ['message' => 'private explanation failure'],
                ], 503),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Apa yang dapat dilakukan Neomatra?',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Konten panduan yang sudah divalidasi admin.',
            )
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            )
            ->assertDontSee('private explanation failure');

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'failure_code' => 'provider_unavailable',
            'is_unanswered' => false,
            'unanswered_reason' => null,
        ]);
    }

    public function test_product_no_result_has_a_useful_fallback_when_chat_fails(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse($this->genericIntent('HP')))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'error' => ['message' => 'private explanation failure'],
                ], 503),
        ]);

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Saya ingin cari HP',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('listings', [])
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertDontSee('private explanation failure');
        $this->assertStringContainsString(
            'Belum ditemukan listing aktif yang cocok untuk "HP".',
            (string) $response->json('answer'),
        );

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'response_mode' => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            'failure_code' => 'provider_unavailable',
            'is_unanswered' => true,
            'unanswered_reason' => 'no_attribute_valid_result',
        ]);
    }

    public function test_knowledge_only_chat_failure_does_not_return_an_unrelated_top_k_answer(): void
    {
        $this->createKnowledge('Panduan transaksi', [-1, 0]);
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse([
                    ...$this->genericIntent('informasi prototipe'),
                    'intent_type' => 'knowledge_question',
                ]))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'error' => ['message' => 'private explanation failure'],
                ], 503),
        ]);

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Informasi prototipe yang tidak tersedia',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertDontSee('Konten panduan yang sudah divalidasi admin.')
            ->assertDontSee('private explanation failure');
        $this->assertStringContainsString(
            'belum dapat dipastikan',
            (string) $response->json('answer'),
        );
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_GENERATION_FAILED,
            'is_unanswered' => true,
            'unanswered_reason' => 'context_insufficient',
            'failure_code' => 'provider_unavailable',
        ]);
    }

    public function test_product_query_uses_web_grounding_as_the_primary_external_source(): void
    {
        config(['ai.chat.web_grounding.enabled' => true]);
        $knowledge = $this->createKnowledge(
            'Panduan pembelian perangkat',
            [1, 0],
        );
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse(
                    $this->genericIntent('Xiaomi Redmi Note 12 Pro 5G'),
                ))
                ->push($this->embeddingResponse([1, 0]))
                ->push($this->webGroundingResponse(
                    'Xiaomi Redmi Note 12 Pro 5G adalah ponsel kelas menengah dengan konektivitas 5G.',
                )),
        ]);

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Xiaomi Redmi Note 12 Pro 5G',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Xiaomi Redmi Note 12 Pro 5G adalah ponsel kelas menengah dengan konektivitas 5G.',
            )
            ->assertJsonPath('sources.0.kind', 'web')
            ->assertJsonPath('sources.0.publisher', 'example.com')
            ->assertJsonCount(1, 'sources')
            ->assertJsonPath('matches.0.id', $knowledge->id)
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_WEB_GROUNDED,
            )
            ->assertJsonPath('meta.web_grounding.requested', true)
            ->assertJsonPath('meta.web_grounding.used', true)
            ->assertJsonPath('meta.web_grounding.fallback_used', false)
            ->assertJsonPath('meta.chat.explanation_api_used', false)
            ->assertJsonPath('meta.similarity_threshold', null);

        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), 'feature-web-model')) {
                return false;
            }

            $payload = $request->data();

            return data_get($payload, 'tools.0.googleSearch') !== null
                && ! data_get(
                    $payload,
                    'generationConfig.responseSchema',
                );
        });
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'response_mode' => ChatbotQueryLog::MODE_WEB_GROUNDED,
            'is_unanswered' => false,
        ]);
    }

    public function test_web_grounding_failure_falls_back_to_the_regular_chat_model_safely(): void
    {
        config(['ai.chat.web_grounding.enabled' => true]);
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse(
                    $this->genericIntent('Xiaomi Redmi Note 12 Pro 5G'),
                ))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'error' => [
                        'status' => 'RESOURCE_EXHAUSTED',
                        'message' => 'private search quota detail',
                    ],
                ], 429)
                ->push($this->explanationResponse(
                    'Saya tetap dapat membantu dengan pengetahuan umum, tetapi informasi ini belum diverifikasi langsung dari web.',
                    false,
                )),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Xiaomi Redmi Note 12 Pro 5G',
        ])
            ->assertOk()
            ->assertJsonPath(
                'answer',
                'Saya tetap dapat membantu dengan pengetahuan umum, tetapi informasi ini belum diverifikasi langsung dari web.',
            )
            ->assertJsonPath('sources', [])
            ->assertJsonPath('matches', [])
            ->assertJsonPath('meta.web_grounding.requested', true)
            ->assertJsonPath('meta.web_grounding.used', false)
            ->assertJsonPath('meta.web_grounding.fallback_used', true)
            ->assertJsonPath(
                'meta.web_grounding.failure',
                'quota_or_rate_limit',
            )
            ->assertDontSee('private search quota detail');

        Http::assertSentCount(4);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'failure_code' => 'quota_or_rate_limit',
            'unanswered_reason' => 'web_grounding_unavailable',
        ]);
    }

    public function test_fallback_telemetry_combines_consumed_web_and_regular_chat_tokens(): void
    {
        config(['ai.chat.web_grounding.enabled' => true]);
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse(
                    $this->genericIntent('Produk demo'),
                ))
                ->push($this->embeddingResponse([1, 0]))
                ->push([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'text' => 'Respons tanpa metadata grounding.',
                        ]]],
                        'finishReason' => 'STOP',
                    ]],
                    'usageMetadata' => [
                        'promptTokenCount' => 5,
                        'candidatesTokenCount' => 3,
                        'totalTokenCount' => 8,
                    ],
                ])
                ->push($this->explanationResponse(
                    'Fallback model reguler digunakan.',
                    false,
                )),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Produk demo',
        ])
            ->assertOk()
            ->assertJsonPath('meta.chat.explanation_api_used', true)
            ->assertJsonPath('meta.web_grounding.used', false)
            ->assertJsonPath('meta.usage.prompt_tokens', 17)
            ->assertJsonPath('meta.usage.output_tokens', 7)
            ->assertJsonPath('meta.usage.total_tokens', 24);

        $this->assertDatabaseHas('chatbot_query_logs', [
            'prompt_tokens' => 17,
            'output_tokens' => 7,
            'total_tokens' => 24,
            'failure_code' => 'grounding_unavailable',
        ]);
    }

    public function test_bounded_history_is_forwarded_to_intent_and_answer_calls(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse($this->generalIntent()))
                ->push($this->embeddingResponse([1, 0]))
                ->push($this->explanationResponse(
                    'Saya masih memahami produk dari percakapan sebelumnya.',
                    false,
                )),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Coba jelaskan lagi.',
            'history' => [
                ['role' => 'user', 'content' => 'Xiaomi Redmi Note 12 Pro 5G'],
                ['role' => 'assistant', 'content' => 'Produk tersebut belum ada di katalog.'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('intent.intent_type', 'general_question')
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_GENERAL_MODEL,
            );

        $requests = Http::recorded();
        $this->assertCount(3, $requests);

        foreach ([$requests[0][0], $requests[2][0]] as $request) {
            $this->assertSame(
                ['user'],
                array_column($request->data()['contents'], 'role'),
            );
            $this->assertStringContainsString(
                'Xiaomi Redmi Note 12 Pro 5G',
                data_get($request->data(), 'contents.0.parts.0.text'),
            );
            $this->assertStringContainsString(
                '"role":"assistant"',
                data_get($request->data(), 'contents.0.parts.0.text'),
            );
        }
    }

    public function test_named_rate_limiter_stops_second_request_before_api_calls(): void
    {
        config(['chatbot.rate_limit' => 1]);
        $this->fakeFlow(
            $this->genericIntent('produk demo'),
            [1, 0],
            'Data belum tersedia.',
        );

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
            ->postJson('/api/chatbot/query', ['message' => 'First'])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
            ->postJson('/api/chatbot/query', ['message' => 'Second'])
            ->assertTooManyRequests();

        Http::assertSentCount(3);
        $this->assertDatabaseCount('chatbot_query_logs', 1);
    }

    /** @param list<float|int> $embedding */
    private function seedCatalogWithEmbeddings(array $embedding): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $model = (string) config('ai.embedding.model');
        $dimensions = (int) config('ai.embedding.dimensions');

        Product::query()
            ->with('category')
            ->each(function (Product $product) use (
                $embedding,
                $model,
                $dimensions,
            ): void {
                $product->forceFill([
                    'embedding' => $embedding,
                    'embedding_hash' => $product->expectedEmbeddingHash(
                        $model,
                        $dimensions,
                    ),
                    'embedding_model' => $model,
                    'embedding_dimensions' => $dimensions,
                    'embedded_at' => now(),
                    'embedding_error' => null,
                ])->save();
            });
    }

    /** @param list<float|int> $embedding */
    private function createKnowledge(
        string $title,
        array $embedding,
    ): KnowledgeItem {
        $item = KnowledgeItem::query()->create([
            'title' => $title,
            'content' => 'Konten panduan yang sudah divalidasi admin.',
            'embedding' => $embedding,
            'is_active' => true,
        ]);
        $model = (string) config('ai.embedding.model');
        $dimensions = (int) config('ai.embedding.dimensions');
        $item->forceFill([
            'embedding_hash' => $item->expectedEmbeddingHash(
                $model,
                $dimensions,
            ),
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
        ])->save();

        return $item;
    }

    /** @param array<string, mixed> $intent */
    private function fakeFlow(
        array $intent,
        array $queryVector,
        string $answer,
        bool $supportedByContext = true,
    ): void {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse($intent))
                ->push($this->embeddingResponse($queryVector))
                ->push($this->explanationResponse(
                    $answer,
                    $supportedByContext,
                )),
        ]);
    }

    /** @param array<string, mixed> $intent */
    private function intentResponse(array $intent): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode(
                        $intent,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                ]]],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    /** @param list<float|int> $vector */
    private function embeddingResponse(array $vector): array
    {
        return ['embedding' => ['values' => $vector]];
    }

    private function explanationResponse(
        string $answer,
        bool $supportedByContext = true,
    ): array {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode([
                    'answer' => $answer,
                    'supported_by_context' => $supportedByContext,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 12,
                'candidatesTokenCount' => 4,
                'totalTokenCount' => 16,
            ],
            'modelVersion' => 'feature-chat-model-001',
        ];
    }

    private function webGroundingResponse(string $answer): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => $answer]]],
                'finishReason' => 'STOP',
                'groundingMetadata' => [
                    'searchEntryPoint' => [
                        'renderedContent' => '<div>Google Search</div>',
                    ],
                    'groundingChunks' => [[
                        'web' => [
                            'uri' => 'https://example.com/xiaomi',
                            'title' => 'Xiaomi Redmi Note 12 Pro 5G',
                        ],
                    ]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 20,
                'candidatesTokenCount' => 10,
                'totalTokenCount' => 30,
            ],
            'modelVersion' => 'feature-web-model-001',
        ];
    }

    /** @return array<string, mixed> */
    private function rtxIntent(array $overrides = []): array
    {
        return [
            ...$this->genericIntent('RTX 3060'),
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB',
            'conditions' => ['used'],
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function genericIntent(string $product): array
    {
        return [
            'product' => $product,
            'intent_type' => 'product_search',
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
        ];
    }

    /** @return array<string, mixed> */
    private function generalIntent(): array
    {
        return [
            ...$this->genericIntent(''),
            'intent_type' => 'general_question',
        ];
    }
}
