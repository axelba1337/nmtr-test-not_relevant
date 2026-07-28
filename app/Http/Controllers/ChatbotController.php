<?php

namespace App\Http\Controllers;

use App\Helpers\Normalizer;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Services\ChatbotActivityLogger;
use App\Services\GeminiService;
use App\Services\KnowledgeRetriever;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ChatbotController extends Controller
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly KnowledgeRetriever $retriever,
        private readonly ChatbotActivityLogger $activityLogger,
    ) {}

    public function suggestions(): JsonResponse
    {
        $suggestions = KnowledgeItem::query()
            ->active()
            ->orderedForSuggestions()
            ->limit(4)
            ->get(['id', 'title'])
            ->map(static fn (KnowledgeItem $item): array => [
                'id' => (int) $item->id,
                'question' => (string) $item->title,
            ])
            ->all();

        return response()->json([
            'suggestions' => $suggestions,
        ]);
    }

    public function query(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $message = (string) $validated['message'];
        $normalizedMessage = Normalizer::text($message);

        if ($normalizedMessage === '') {
            throw ValidationException::withMessages([
                'message' => ['Kolom message wajib diisi.'],
            ]);
        }

        $queryEmbedding = $this->gemini->embed(
            $normalizedMessage,
            'RETRIEVAL_QUERY',
        );

        if ($queryEmbedding === null) {
            $this->activityLogger->record(
                ChatbotQueryLog::STATUS_EMBEDDING_FAILED,
            );

            return $this->error(
                'embedding_unavailable',
                'Layanan embedding sementara tidak tersedia.',
                503,
            );
        }

        $matches = $this->retriever->retrieve($queryEmbedding);

        if ($matches === []) {
            $this->activityLogger->record(
                ChatbotQueryLog::STATUS_KNOWLEDGE_UNAVAILABLE,
            );

            return $this->error(
                'knowledge_unavailable',
                'Knowledge yang dapat digunakan belum tersedia.',
                503,
            );
        }

        $answer = $this->gemini->generateAnswer($message, $matches);
        $top1Similarity = (float) $matches[0]['similarity'];

        if ($answer === null) {
            $this->activityLogger->record(
                ChatbotQueryLog::STATUS_GENERATION_FAILED,
                $top1Similarity,
            );

            return $this->error(
                'generation_unavailable',
                'Layanan jawaban sementara tidak tersedia.',
                502,
            );
        }

        $this->activityLogger->record(
            ChatbotQueryLog::STATUS_ANSWERED,
            $top1Similarity,
        );

        return response()->json([
            'answer' => $answer,
            'matches' => array_map(
                static fn (array $match): array => [
                    'id' => (int) $match['id'],
                    'title' => (string) $match['title'],
                    'similarity' => round(
                        (float) $match['similarity'],
                        6,
                    ),
                ],
                $matches,
            ),
        ]);
    }

    private function error(
        string $code,
        string $message,
        int $status,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
