<?php

namespace App\Services\AI;

use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\EmbeddingPurpose;
use App\Data\AI\EmbeddingResult;
use App\Data\IntegrationFailure;
use App\Helpers\Similarity;

final class GeminiEmbeddingAdapter implements EmbeddingClient
{
    public function __construct(
        private readonly GeminiHttpClient $http,
    ) {}

    public function embed(
        string $text,
        EmbeddingPurpose $purpose,
        ?string $title = null,
    ): EmbeddingResult {
        $text = trim($text);
        $model = $this->model();
        $dimensions = $this->dimensions();

        if ($text === '') {
            return $this->failed(
                'validation',
                'Teks embedding tidak boleh kosong.',
            );
        }

        $embeddingConfig = [
            'outputDimensionality' => $dimensions,
            'autoTruncate' => true,
        ];

        if ($this->usesPromptTaskInstructions($model)) {
            $text = $this->embeddingTwoText($text, $purpose, $title);
        } else {
            $embeddingConfig['taskType'] = $purpose->isQuery()
                ? 'RETRIEVAL_QUERY'
                : 'RETRIEVAL_DOCUMENT';

            if (! $purpose->isQuery()
                && $title !== null
                && trim($title) !== '') {
                $embeddingConfig['title'] = mb_substr(
                    trim($title),
                    0,
                    500,
                    'UTF-8',
                );
            }
        }

        $response = $this->http->post(
            'embedding',
            'embedding',
            $model,
            'models/'.rawurlencode($model).':embedContent',
            [
                'model' => 'models/'.$model,
                'content' => [
                    'parts' => [[
                        'text' => $text,
                    ]],
                ],
                'embedContentConfig' => $embeddingConfig,
            ],
        );

        if (! $response->succeeded()) {
            return EmbeddingResult::failed(
                $model,
                $dimensions,
                $response->failure ?? new IntegrationFailure(
                    $this->provider(),
                    'embedding',
                    'client_error',
                    'Permintaan embedding tidak dapat diproses.',
                    $model,
                ),
            );
        }

        $vector = $response->response->json('embedding.values');
        $validated = is_array($vector)
            ? Similarity::validatedVector($vector, $dimensions)
            : null;

        if ($validated === null) {
            return $this->failed(
                'invalid_vector',
                'Layanan embedding mengembalikan vector yang tidak valid.',
                $response->response->status(),
            );
        }

        return EmbeddingResult::success($validated, $model, $dimensions);
    }

    public function provider(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        $model = trim((string) config(
            'ai.embedding.model',
            'gemini-embedding-2',
        ));

        return str_starts_with($model, 'models/')
            ? substr($model, strlen('models/'))
            : $model;
    }

    public function dimensions(): int
    {
        return max(1, (int) config('ai.embedding.dimensions', 768));
    }

    private function usesPromptTaskInstructions(string $model): bool
    {
        return $model === 'gemini-embedding-2'
            || str_starts_with($model, 'gemini-embedding-2-');
    }

    private function embeddingTwoText(
        string $text,
        EmbeddingPurpose $purpose,
        ?string $title,
    ): string {
        if ($purpose->isQuery()) {
            return 'task: search result | query: '.$text;
        }

        $title = is_string($title) && trim($title) !== ''
            ? mb_substr(trim($title), 0, 500, 'UTF-8')
            : 'none';

        return "title: {$title} | text: {$text}";
    }

    private function failed(
        string $category,
        string $message,
        ?int $status = null,
    ): EmbeddingResult {
        return EmbeddingResult::failed(
            $this->model(),
            $this->dimensions(),
            new IntegrationFailure(
                $this->provider(),
                'embedding',
                $category,
                $message,
                $this->model(),
                $status,
            ),
        );
    }
}
