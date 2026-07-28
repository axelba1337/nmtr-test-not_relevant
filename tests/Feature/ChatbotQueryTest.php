<?php

namespace Tests\Feature;

use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
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
            'services.gemini.key' => 'feature-test-key',
            'chatbot.models.embed' => 'feature-embedding-model',
            'chatbot.models.chat' => 'feature-chat-model',
            'chatbot.embedding_dimensions' => 2,
            'chatbot.top_k' => 3,
            'chatbot.max_context_chars' => 1000,
            'chatbot.http.retry' => 0,
            'chatbot.rate_limit' => 100,
        ]);

        Http::preventStrayRequests();
    }

    public function test_message_validation_rejects_invalid_input_without_an_api_call(): void
    {
        Http::fake();

        $payloads = [
            [],
            ['message' => ''],
            ['message' => " \t\n "],
            ['message' => null],
            ['message' => ['not-a-string']],
            ['message' => str_repeat('a', 2001)],
        ];

        foreach ($payloads as $payload) {
            $this->postJson('/api/chatbot/query', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('message');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('chatbot_query_logs', 0);
    }

    public function test_empty_knowledge_base_returns_a_service_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [1, 0]],
            ]),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'What is available?',
        ])
            ->assertStatus(503)
            ->assertExactJson([
                'error' => [
                    'code' => 'knowledge_unavailable',
                    'message' => 'Knowledge yang dapat digunakan belum tersedia.',
                ],
            ]);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_KNOWLEDGE_UNAVAILABLE,
            'top1_similarity' => null,
        ]);
    }

    public function test_embedding_api_failure_returns_a_service_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'private upstream detail'],
            ], 503),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Question',
        ])
            ->assertStatus(503)
            ->assertExactJson([
                'error' => [
                    'code' => 'embedding_unavailable',
                    'message' => 'Layanan embedding sementara tidak tersedia.',
                ],
            ])
            ->assertDontSee('private upstream detail');

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_EMBEDDING_FAILED,
            'top1_similarity' => null,
        ]);
    }

    public function test_chat_api_failure_returns_a_gateway_error(): void
    {
        $this->createCurrentItem('Available knowledge', [1, 0]);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'embedding' => ['values' => [1, 0]],
                ])
                ->push([
                    'error' => ['message' => 'private upstream detail'],
                ], 500),
        ]);

        $this->postJson('/api/chatbot/query', [
            'message' => 'Question',
        ])
            ->assertStatus(502)
            ->assertExactJson([
                'error' => [
                    'code' => 'generation_unavailable',
                    'message' => 'Layanan jawaban sementara tidak tersedia.',
                ],
            ])
            ->assertDontSee('private upstream detail');

        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_GENERATION_FAILED,
            'top1_similarity' => 1,
        ]);
    }

    public function test_successful_endpoint_returns_answer_and_public_match_fields(): void
    {
        $item = $this->createCurrentItem('Available knowledge', [1, 0]);
        $this->fakeSuccessfulGemini([1, 0], 'Answer from knowledge.');

        $response = $this->postJson('/api/chatbot/query', [
            'message' => '  WHAT is available?  ',
        ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'answer' => 'Answer from knowledge.',
                'matches' => [[
                    'id' => $item->id,
                    'title' => 'Available knowledge',
                    'similarity' => 1,
                ]],
            ])
            ->assertJsonMissingPath('matches.0.content')
            ->assertJsonMissingPath('matches.0.embedding');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), ':embedContent')) {
                return false;
            }

            return data_get(
                $request->data(),
                'content.parts.0.text',
            ) === 'what is available?';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), ':generateContent')) {
                return false;
            }

            $prompt = (string) data_get(
                $request->data(),
                'contents.0.parts.0.text',
            );

            return str_contains($prompt, 'Available knowledge')
                && str_contains($prompt, 'WHAT is available?');
        });
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'top1_similarity' => 1,
        ]);
    }

    public function test_a_negative_match_still_reaches_generation(): void
    {
        $item = $this->createCurrentItem('Opposite direction', [-1, 0]);
        $this->fakeSuccessfulGemini([1, 0], 'Context was inspected.');

        $this->postJson('/api/chatbot/query', [
            'message' => 'Question',
        ])
            ->assertOk()
            ->assertJsonPath('answer', 'Context was inspected.')
            ->assertJsonPath('matches.0.id', $item->id)
            ->assertJsonPath('matches.0.similarity', -1);

        Http::assertSentCount(2);
        $this->assertDatabaseHas('chatbot_query_logs', [
            'status' => ChatbotQueryLog::STATUS_ANSWERED,
            'top1_similarity' => -1,
        ]);
    }

    public function test_named_rate_limiter_returns_429(): void
    {
        config(['chatbot.rate_limit' => 1]);
        $this->createCurrentItem('Available knowledge', [1, 0]);
        $this->fakeSuccessfulGemini([1, 0], 'Answer.');

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
            ->postJson('/api/chatbot/query', ['message' => 'First'])
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.15'])
            ->postJson('/api/chatbot/query', ['message' => 'Second'])
            ->assertTooManyRequests();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('chatbot_query_logs', 1);
    }

    private function createCurrentItem(
        string $title,
        array $embedding,
    ): KnowledgeItem {
        $item = KnowledgeItem::query()->create([
            'title' => $title,
            'content' => "{$title} content",
            'embedding' => $embedding,
            'is_active' => true,
        ]);
        $item->update([
            'embedding_hash' => $item->expectedEmbeddingHash(),
            'embedding_model' => (string) config('chatbot.models.embed'),
            'embedded_at' => now(),
        ]);

        return $item;
    }

    private function fakeSuccessfulGemini(
        array $queryVector,
        string $answer,
    ): void {
        Http::fake(
            static function (Request $request) use (
                $queryVector,
                $answer,
            ) {
                if (str_ends_with(
                    $request->url(),
                    ':embedContent',
                )) {
                    return Http::response([
                        'embedding' => ['values' => $queryVector],
                    ]);
                }

                return Http::response([
                    'candidates' => [[
                        'content' => [
                            'parts' => [
                                ['text' => $answer],
                            ],
                        ],
                        'finishReason' => 'STOP',
                    ]],
                ]);
            },
        );
    }
}
