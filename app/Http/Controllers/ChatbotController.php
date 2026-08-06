<?php

namespace App\Http\Controllers;

use App\Contracts\AI\ChatClient;
use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\ChatExplanationRequest;
use App\Data\AI\EmbeddingPurpose;
use App\Data\AI\ParsedIntent;
use App\Helpers\Normalizer;
use App\Http\Requests\ChatbotQueryRequest;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Product;
use App\Services\ChatbotActivityLogger;
use App\Services\KnowledgeRetriever;
use App\Services\Search\IntentResolver;
use App\Services\Search\ListingSearchService;
use App\Services\Search\ProductRetriever;
use Illuminate\Http\JsonResponse;

final class ChatbotController extends Controller
{
    public function __construct(
        private readonly ChatClient $chat,
        private readonly EmbeddingClient $embedding,
        private readonly IntentResolver $intentResolver,
        private readonly ProductRetriever $productRetriever,
        private readonly ListingSearchService $listingSearch,
        private readonly KnowledgeRetriever $knowledgeRetriever,
        private readonly ChatbotActivityLogger $activityLogger,
    ) {}

    public function suggestions(): JsonResponse
    {
        $suggestions = KnowledgeItem::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                        ->where('is_active', true));
            })
            ->orderedForSuggestions()
            ->limit(4)
            ->get(['id', 'title'])
            ->map(static fn (KnowledgeItem $item): array => [
                'id' => (int) $item->id,
                'question' => (string) $item->title,
            ])
            ->all();

        return response()->json(['suggestions' => $suggestions]);
    }

    public function query(ChatbotQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $message = (string) $validated['message'];
        $normalized = Normalizer::text($message);
        $history = array_values($validated['history'] ?? []);
        $filters = collect($validated)
            ->except(['message', 'session_key', 'history'])
            ->all();
        $matchesKnowledgeTitle = $this->matchesKnowledgeTitle($normalized);

        if ($matchesKnowledgeTitle) {
            $filters['intent_type'] = ParsedIntent::TYPE_KNOWLEDGE_QUESTION;
        }

        $resolved = $this->intentResolver->resolve(
            $message,
            $filters,
            $history,
        );
        $intent = $resolved->intent;

        if ($intent->needsClarification
            && $intent->intentType === ParsedIntent::TYPE_PRODUCT_SEARCH) {
            $answer = $intent->clarificationQuestion
                ?? 'Mohon jelaskan produk atau varian yang ingin dicari.';
            $this->record(
                $validated,
                $normalized,
                $intent->toArray(),
                $filters,
                ChatbotQueryLog::STATUS_NO_RESULTS,
                ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
                null,
                0,
                true,
                'clarification_required',
                $resolved->chatFailure?->category,
            );

            return response()->json([
                'answer' => $answer,
                'intent' => $intent->toArray(),
                'needs_clarification' => true,
                'listings' => [],
                'matches' => [],
                'sources' => [],
                'meta' => $this->meta(
                    false,
                    $resolved->usedChatApi,
                    false,
                    false,
                ),
            ]);
        }

        $webGroundingRequested = $this->shouldUseWebGrounding(
            $intent,
            $matchesKnowledgeTitle,
        );
        $queryText = $this->queryEmbeddingText(
            $intent->toArray(),
            $normalized,
        );
        $embedding = $this->embedding->embed(
            $queryText,
            EmbeddingPurpose::Query,
        );

        if (! $embedding->succeeded() && ! $webGroundingRequested) {
            $this->record(
                $validated,
                $normalized,
                $intent->toArray(),
                $filters,
                ChatbotQueryLog::STATUS_EMBEDDING_FAILED,
                ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
                null,
                0,
                true,
                'embedding_unavailable',
                $embedding->failure?->category,
            );

            return response()->json([
                'error' => [
                    'code' => 'embedding_unavailable',
                    'message' => $embedding->failure?->safeMessage
                        ?? 'Layanan semantic search sementara tidak tersedia.',
                ],
                'intent' => $intent->toArray(),
            ], 503);
        }

        $productCandidates = [];
        $listings = [];
        $knowledge = [];

        if ($embedding->succeeded()) {
            $intentForMatching = [
                ...$intent->toArray(),
                'query' => $normalized,
            ];
            $productCandidates = $intent->intentType
                === ParsedIntent::TYPE_PRODUCT_SEARCH
                ? $this->productRetriever->retrieve(
                    $embedding->vector,
                    $embedding->model,
                    $embedding->dimensions,
                    $intentForMatching,
                )
                : [];
            $listings = $productCandidates === []
                ? []
                : $this->listingSearch->search($productCandidates, $intent);
            $knowledge = $this->knowledgeRetriever->retrieve(
                $embedding->vector,
            );
        }

        $top1 = $this->topSimilarity($productCandidates, $knowledge);
        $contextAvailable = $listings !== [] || $knowledge !== [];
        $knowledgeContext = array_map(
            static fn (array $item): array => [
                'title' => $item['title'],
                'content' => $item['content'],
            ],
            $webGroundingRequested
                ? array_slice($knowledge, 0, 1)
                : $knowledge,
        );
        $explanationRequest = new ChatExplanationRequest(
            $message,
            $intent,
            $this->contextListings($listings),
            $knowledgeContext,
            $history,
        );
        $webResult = $webGroundingRequested
            ? $this->chat->explainWithWebGrounding($explanationRequest)
            : null;
        $usedWebGrounding = $webResult?->succeeded() === true;
        $webFailure = $usedWebGrounding ? null : $webResult?->failure;
        $chatResult = $usedWebGrounding
            ? $webResult
            : $this->chat->explain($explanationRequest);
        $usedChatExplanation = $chatResult->succeeded();
        $usedStructuredExplanation = ! $usedWebGrounding
            && $usedChatExplanation;
        $usage = $usedWebGrounding
            ? $chatResult->usage
            : $this->combinedUsage($webResult?->usage, $chatResult->usage);
        $exactKnowledgeAnswer = $usedChatExplanation
            ? null
            : $this->exactKnowledgeAnswer($normalized, $knowledge);
        $supportedByContext = match (true) {
            $usedWebGrounding => true,
            ! $contextAvailable => false,
            $usedChatExplanation => $chatResult->supportedByContext === true,
            $listings !== [] => true,
            $exactKnowledgeAnswer !== null => true,
            default => false,
        };
        $unanswered = ! $supportedByContext;
        $answer = $usedChatExplanation
            ? (string) $chatResult->text
            : $this->deterministicAnswer(
                $listings,
                $intent,
                $exactKnowledgeAnswer,
                $webGroundingRequested && $webFailure !== null,
            );
        $mode = match (true) {
            $usedWebGrounding => ChatbotQueryLog::MODE_WEB_GROUNDED,
            ! $usedChatExplanation => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
            $listings !== [] => ChatbotQueryLog::MODE_MARKETPLACE_RESULTS,
            $knowledge !== [] => ChatbotQueryLog::MODE_KNOWLEDGE_ONLY,
            $intent->intentType === ParsedIntent::TYPE_GENERAL_QUESTION => ChatbotQueryLog::MODE_GENERAL_MODEL,
            default => ChatbotQueryLog::MODE_DETERMINISTIC_FALLBACK,
        };
        $status = match (true) {
            ! $unanswered => ChatbotQueryLog::STATUS_ANSWERED,
            ! $usedChatExplanation
                && $intent->intentType === ParsedIntent::TYPE_KNOWLEDGE_QUESTION
                && $knowledge !== [] => ChatbotQueryLog::STATUS_GENERATION_FAILED,
            default => ChatbotQueryLog::STATUS_NO_RESULTS,
        };
        $unansweredReason = match (true) {
            ! $unanswered => null,
            $webGroundingRequested && $webFailure !== null
                && ! $contextAvailable => 'web_grounding_unavailable',
            $usedChatExplanation && $contextAvailable => 'context_insufficient',
            $intent->intentType === ParsedIntent::TYPE_PRODUCT_SEARCH => 'no_attribute_valid_result',
            $contextAvailable => 'context_insufficient',
            default => 'knowledge_unavailable',
        };

        $this->record(
            $validated,
            $normalized,
            $intent->toArray(),
            $filters,
            $status,
            $mode,
            $top1,
            count($listings),
            $unanswered,
            $unansweredReason,
            $chatResult->failure?->category
                ?? $webFailure?->category
                ?? $embedding->failure?->category,
            $usage,
        );

        $sources = $chatResult->sources;
        $knowledgeForResponse = match (true) {
            $webGroundingRequested && ! $supportedByContext => [],
            $webGroundingRequested => array_slice($knowledge, 0, 1),
            default => $knowledge,
        };

        return response()->json([
            'answer' => $answer,
            'intent' => $intent->toArray(),
            'needs_clarification' => false,
            'listings' => $this->publicListings($listings),
            'matches' => array_map(
                static fn (array $match): array => [
                    'id' => (int) $match['id'],
                    'title' => (string) $match['title'],
                    'similarity' => round((float) $match['similarity'], 6),
                ],
                $knowledgeForResponse,
            ),
            'sources' => $sources,
            'search_entry_point_html' => $usedWebGrounding
                ? $chatResult->searchEntryPointHtml
                : null,
            'meta' => [
                ...$this->meta(
                    $usedStructuredExplanation,
                    $resolved->usedChatApi,
                    $webGroundingRequested,
                    $usedWebGrounding,
                    $webFailure?->category,
                    $embedding->succeeded(),
                    $embedding->failure?->category,
                ),
                'mode' => $mode,
                'top1_similarity' => $top1 === null ? null : round($top1, 6),
                'usage' => $usage,
            ],
        ]);
    }

    /**
     * @param  array{prompt_tokens: int|null, output_tokens: int|null, total_tokens: int|null}|null  $first
     * @param  array{prompt_tokens: int|null, output_tokens: int|null, total_tokens: int|null}  $second
     * @return array{prompt_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    private function combinedUsage(?array $first, array $second): array
    {
        $result = [];

        foreach (['prompt_tokens', 'output_tokens', 'total_tokens'] as $key) {
            $values = array_filter(
                [$first[$key] ?? null, $second[$key] ?? null],
                static fn (mixed $value): bool => is_int($value),
            );
            $result[$key] = $values === [] ? null : array_sum($values);
        }

        return $result;
    }

    /** @param array<string, mixed> $intent */
    private function queryEmbeddingText(
        array $intent,
        string $normalizedMessage,
    ): string {
        if (in_array($intent['intent_type'] ?? null, [
            ParsedIntent::TYPE_KNOWLEDGE_QUESTION,
            ParsedIntent::TYPE_GENERAL_QUESTION,
        ], true)) {
            $label = ($intent['intent_type'] ?? null)
                === ParsedIntent::TYPE_KNOWLEDGE_QUESTION
                ? 'Pertanyaan knowledge'
                : 'Pertanyaan umum';

            return $label.': '.$normalizedMessage;
        }

        $parts = [];

        foreach ([
            'product' => 'Produk',
            'category' => 'Kategori',
            'brand' => 'Merek',
            'model' => 'Model',
            'variant' => 'Varian',
            'sku_or_part_number' => 'SKU/Part Number',
        ] as $key => $label) {
            $value = $intent[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parts[] = "{$label}: ".trim($value);
            }
        }

        return implode("\n", $parts);
    }

    private function matchesKnowledgeTitle(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        return KnowledgeItem::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                        ->where('is_active', true));
            })
            ->select(['id', 'title'])
            ->lazyById(100)
            ->contains(
                static fn (KnowledgeItem $item): bool => Normalizer::text(
                    (string) $item->title,
                ) === $normalized,
            );
    }

    /**
     * @param  list<array{product: Product, similarity: float, matched_via: string}>  $products
     * @param  list<array<string, mixed>>  $knowledge
     */
    private function topSimilarity(array $products, array $knowledge): ?float
    {
        if ($products !== []) {
            return (float) $products[0]['similarity'];
        }

        return $knowledge !== [] ? (float) $knowledge[0]['similarity'] : null;
    }

    /** @param list<array<string, mixed>> $listings */
    private function contextListings(array $listings): array
    {
        return array_map(static function (array $listing): array {
            unset($listing['image_url'], $listing['source_url']);

            return $listing;
        }, $listings);
    }

    /** @param list<array<string, mixed>> $listings */
    private function publicListings(array $listings): array
    {
        return array_map(static function (array $listing): array {
            $listing['source_url'] = route('listings.open', [
                'listing' => $listing['id'],
            ]);
            $listing['comparison_url'] = route('products.compare', [
                'product' => $listing['product_id'],
            ]);

            return $listing;
        }, $listings);
    }

    /** @param  list<array<string, mixed>>  $listings */
    private function deterministicAnswer(
        array $listings,
        ParsedIntent $intent,
        ?string $exactKnowledgeAnswer,
        bool $webUnavailable = false,
    ): string {
        if ($listings !== []) {
            $first = $listings[0];

            return sprintf(
                'Ditemukan %d listing. Hasil teratas adalah %s dengan total biaya estimasi Rp%s. Periksa detail, sumber, dan sinyal risiko sebelum mengambil keputusan.',
                count($listings),
                (string) $first['title'],
                number_format((int) $first['costs']['total'], 0, ',', '.'),
            );
        }

        if ($exactKnowledgeAnswer !== null) {
            return $exactKnowledgeAnswer;
        }

        if ($intent->intentType === ParsedIntent::TYPE_PRODUCT_SEARCH) {
            $product = mb_substr(trim($intent->product), 0, 160, 'UTF-8');

            $answer = $product === ''
                ? 'Belum ditemukan listing aktif yang cocok. Coba sebutkan merek, model, varian, kondisi, budget, atau lokasi dengan lebih spesifik.'
                : sprintf(
                    'Belum ditemukan listing aktif yang cocok untuk "%s". Coba perjelas merek, model, varian, kondisi, budget, atau tambahkan data produk dan listing melalui panel admin.',
                    $product,
                );

            return $webUnavailable
                ? $answer.' Pencarian web langsung juga sementara tidak tersedia; silakan coba kembali.'
                : $answer;
        }

        return $webUnavailable
            ? 'Pencarian web langsung dan layanan penjelasan sementara tidak tersedia. Silakan coba kembali beberapa saat lagi.'
            : 'Layanan penjelasan sedang tidak tersedia dan knowledge Top-K belum dapat dipastikan menjawab pertanyaan ini. Silakan pilih salah satu pertanyaan yang tersedia atau coba kembali.';
    }

    /** @param  list<array<string, mixed>>  $knowledge */
    private function exactKnowledgeAnswer(
        string $normalizedQuestion,
        array $knowledge,
    ): ?string {
        foreach ($knowledge as $item) {
            if (Normalizer::text((string) ($item['title'] ?? ''))
                    !== $normalizedQuestion) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? ''));

            if ($content !== '') {
                return $content;
            }
        }

        return null;
    }

    private function shouldUseWebGrounding(
        ParsedIntent $intent,
        bool $matchesKnowledgeTitle,
    ): bool {
        if (! (bool) config('ai.chat.web_grounding.enabled', true)
            || $matchesKnowledgeTitle) {
            return false;
        }

        return in_array($intent->intentType, [
            ParsedIntent::TYPE_PRODUCT_SEARCH,
            ParsedIntent::TYPE_GENERAL_QUESTION,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>  $filters
     * @param  array{prompt_tokens: int|null, output_tokens: int|null, total_tokens: int|null}  $usage
     */
    private function record(
        array $validated,
        string $normalized,
        array $intent,
        array $filters,
        string $status,
        string $mode,
        ?float $top1,
        int $resultCount,
        bool $unanswered,
        ?string $unansweredReason,
        ?string $failureCode,
        array $usage = [
            'prompt_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
        ],
    ): void {
        $this->activityLogger->record($status, $top1, [
            'response_mode' => $mode,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'output_tokens' => $usage['output_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
            'failure_code' => $failureCode,
            'session_key' => $validated['session_key'] ?? null,
            'query_text' => (string) $validated['message'],
            'normalized_query' => $normalized,
            'intent' => $intent,
            'filters' => $filters,
            'result_count' => $resultCount,
            'is_unanswered' => $unanswered,
            'unanswered_reason' => $unansweredReason,
        ]);
    }

    /** @return array<string, mixed> */
    private function meta(
        bool $usedExplanation,
        bool $usedIntentApi,
        bool $webGroundingRequested,
        bool $usedWebGrounding,
        ?string $webFailure = null,
        ?bool $semanticSearchAvailable = null,
        ?string $embeddingFailure = null,
    ): array {
        return [
            'chat' => [
                'provider' => $this->chat->provider(),
                'model' => $this->chat->model(),
                'web_model' => $this->chat->webModel(),
                'intent_api_used' => $usedIntentApi,
                'explanation_api_used' => $usedExplanation,
            ],
            'web_grounding' => [
                'enabled' => (bool) config(
                    'ai.chat.web_grounding.enabled',
                    true,
                ),
                'requested' => $webGroundingRequested,
                'used' => $usedWebGrounding,
                'fallback_used' => $webGroundingRequested
                    && ! $usedWebGrounding,
                'failure' => $webFailure,
            ],
            'embedding' => [
                'provider' => $this->embedding->provider(),
                'model' => $this->embedding->model(),
                'dimensions' => $this->embedding->dimensions(),
                'query_succeeded' => $semanticSearchAvailable,
                'failure' => $embeddingFailure,
            ],
            'marketplace_provider' => (string) config(
                'marketplace.provider',
                'demo',
            ),
            'top_k' => [
                'candidates' => (int) config(
                    'neomatra.search.product_candidate_k',
                    20,
                ),
                'results' => (int) config(
                    'neomatra.search.result_top_k',
                    5,
                ),
                'knowledge' => (int) config(
                    'neomatra.search.knowledge_top_k',
                    3,
                ),
            ],
            'similarity_threshold' => null,
            'calculations_authority' => 'backend',
        ];
    }
}
