<?php

namespace Tests\Unit;

use App\Services\GeminiService;
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
            'services.gemini.key' => 'unit-test-key',
            'chatbot.models.embed' => 'gemini-embedding-2',
            'chatbot.models.chat' => 'gemini-3.5-flash-lite',
            'chatbot.embedding_dimensions' => 768,
            'chatbot.max_context_chars' => 4000,
            'chatbot.max_output_tokens' => 512,
            'chatbot.http.timeout' => 2,
            'chatbot.http.retry' => 0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_embedding_request_uses_the_v1beta_contract(): void
    {
        $values = array_fill(0, 768, 0.0);
        $values[0] = 1.0;

        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => $values],
            ]),
        ]);

        $result = app(GeminiService::class)->embed(
            'Document body',
            'RETRIEVAL_DOCUMENT',
            'Document title',
        );

        $this->assertSame($values, $result);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent'
                && $request->hasHeader('x-goog-api-key', 'unit-test-key')
                && data_get($payload, 'model') === 'models/gemini-embedding-2'
                && data_get($payload, 'content.parts.0.text') === 'Document body'
                && data_get($payload, 'embedContentConfig.taskType') === 'RETRIEVAL_DOCUMENT'
                && data_get($payload, 'embedContentConfig.title') === 'Document title'
                && data_get($payload, 'embedContentConfig.outputDimensionality') === 768
                && data_get($payload, 'embedContentConfig.autoTruncate') === true;
        });
    }

    public function test_query_embedding_omits_the_optional_title(): void
    {
        config(['chatbot.embedding_dimensions' => 2]);

        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [1, 0]],
            ]),
        ]);

        $this->assertSame(
            [1.0, 0.0],
            app(GeminiService::class)->embed(
                'question',
                'RETRIEVAL_QUERY',
            ),
        );

        Http::assertSent(
            static fn (Request $request): bool => data_get(
                $request->data(),
                'embedContentConfig.taskType',
            ) === 'RETRIEVAL_QUERY'
                && ! array_key_exists(
                    'title',
                    data_get(
                        $request->data(),
                        'embedContentConfig',
                        [],
                    ),
                ),
        );
    }

    public function test_embedding_rejects_invalid_payloads_and_http_failures(): void
    {
        config(['chatbot.embedding_dimensions' => 2]);

        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => ['values' => [1, 0, 0]]])
                ->push(['unexpected' => true])
                ->push([], 429)
                ->push([], 500),
        ]);

        $service = app(GeminiService::class);

        $this->assertNull($service->embed('one', 'RETRIEVAL_QUERY'));
        $this->assertNull($service->embed('two', 'RETRIEVAL_QUERY'));
        $this->assertNull($service->embed('three', 'RETRIEVAL_QUERY'));
        $this->assertNull($service->embed('four', 'RETRIEVAL_QUERY'));

        Http::assertSentCount(4);
    }

    public function test_embedding_handles_a_connection_failure(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out.');
        });

        $this->assertNull(
            app(GeminiService::class)->embed(
                'question',
                'RETRIEVAL_QUERY',
            ),
        );
    }

    public function test_generation_uses_one_bounded_context_and_minimal_options(): void
    {
        config([
            'chatbot.max_context_chars' => 160,
            'chatbot.max_output_tokens' => 37,
        ]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => '  Answer from context.  '],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $answer = app(GeminiService::class)->generateAnswer(
            'What is available?',
            [
                [
                    'id' => 1,
                    'title' => 'Alpha',
                    'content' => str_repeat('A', 200),
                    'similarity' => 0.314159,
                ],
                [
                    'id' => 2,
                    'title' => 'Beta',
                    'content' => str_repeat('B', 200),
                    'similarity' => -0.271828,
                ],
            ],
        );

        $this->assertSame('Answer from context.', $answer);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $contents = data_get($payload, 'contents', []);
            $systemText = data_get(
                $payload,
                'systemInstruction.parts.0.text',
            );
            $userText = data_get($payload, 'contents.0.parts.0.text');
            preg_match(
                '/\AKonteks knowledge:\n(.*)\n\nPertanyaan pengguna:\n/s',
                (string) $userText,
                $contextMatch,
            );

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'unit-test-key')
                && array_keys($payload) === [
                    'systemInstruction',
                    'contents',
                    'generationConfig',
                ]
                && is_string($systemText)
                && str_contains($systemText, 'hanya berdasarkan konteks')
                && count($contents) === 1
                && data_get($contents, '0.role') === 'user'
                && data_get($payload, 'generationConfig') === [
                    'maxOutputTokens' => 37,
                ]
                && isset($contextMatch[1])
                && mb_strlen($contextMatch[1], 'UTF-8') <= 160
                && str_contains($contextMatch[1], 'Alpha')
                && str_contains($contextMatch[1], 'Beta')
                && ! str_contains((string) $userText, '0.314159')
                && ! str_contains((string) $userText, '-0.271828');
        });
    }

    public function test_generation_joins_text_parts(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'First '],
                            ['text' => 'second'],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $this->assertSame(
            'First second',
            app(GeminiService::class)->generateAnswer(
                'Question',
                [[
                    'title' => 'Title',
                    'content' => 'Content',
                ]],
            ),
        );
    }

    public function test_generation_rejects_blocked_empty_and_invalid_responses(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'promptFeedback' => [
                        'blockReason' => 'SAFETY',
                    ],
                ])
                ->push([
                    'candidates' => [[
                        'content' => ['parts' => []],
                        'finishReason' => 'STOP',
                    ]],
                ])
                ->push('not-json', 200, [
                    'Content-Type' => 'text/plain',
                ]),
        ]);

        $service = app(GeminiService::class);
        $matches = [[
            'title' => 'Title',
            'content' => 'Content',
        ]];

        $this->assertNull($service->generateAnswer('one', $matches));
        $this->assertNull($service->generateAnswer('two', $matches));
        $this->assertNull($service->generateAnswer('three', $matches));
    }

    public function test_generation_handles_client_rate_and_server_errors(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push([], 400)
                ->push([], 429)
                ->push([], 503),
        ]);

        $service = app(GeminiService::class);
        $matches = [[
            'title' => 'Title',
            'content' => 'Content',
        ]];

        $this->assertNull($service->generateAnswer('one', $matches));
        $this->assertNull($service->generateAnswer('two', $matches));
        $this->assertNull($service->generateAnswer('three', $matches));
    }

    public function test_generation_handles_a_connection_failure(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out.');
        });

        $this->assertNull(
            app(GeminiService::class)->generateAnswer(
                'Question',
                [[
                    'title' => 'Title',
                    'content' => 'Content',
                ]],
            ),
        );
    }

    public function test_missing_configuration_prevents_requests(): void
    {
        config(['services.gemini.key' => '']);
        Http::fake();

        $service = app(GeminiService::class);

        $this->assertNull(
            $service->embed('Question', 'RETRIEVAL_QUERY'),
        );
        $this->assertNull(
            $service->generateAnswer(
                'Question',
                [[
                    'title' => 'Title',
                    'content' => 'Content',
                ]],
            ),
        );
        Http::assertNothingSent();
    }
}
