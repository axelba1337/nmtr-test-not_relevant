<?php

namespace App\Services;

use App\Models\ChatbotQueryLog;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ChatbotActivityLogger
{
    /**
     * @param  array{
     *     response_mode?: string|null,
     *     prompt_tokens?: int|null,
     *     output_tokens?: int|null,
     *     total_tokens?: int|null,
     *     failure_code?: string|null,
     *     session_key?: string|null,
     *     query_text?: string|null,
     *     normalized_query?: string|null,
     *     intent?: array<string, mixed>|null,
     *     filters?: array<string, mixed>|null,
     *     result_count?: int|null,
     *     is_unanswered?: bool|null,
     *     unanswered_reason?: string|null
     * }  $metadata
     */
    public function record(
        string $status,
        ?float $top1Similarity = null,
        array $metadata = [],
    ): bool {
        if (! in_array($status, ChatbotQueryLog::statuses(), true)) {
            throw new InvalidArgumentException(
                "Status aktivitas chatbot tidak valid: {$status}",
            );
        }

        if ($top1Similarity !== null
            && ! is_finite($top1Similarity)) {
            $top1Similarity = null;
        }

        $responseMode = $metadata['response_mode'] ?? null;

        if (! is_string($responseMode)
            || ! in_array(
                $responseMode,
                ChatbotQueryLog::responseModes(),
                true,
            )) {
            $responseMode = null;
        }

        $failureCode = $metadata['failure_code'] ?? null;

        if (! is_string($failureCode)
            || preg_match('/\A[a-z0-9_]{1,64}\z/', $failureCode) !== 1) {
            $failureCode = null;
        }

        $unansweredReason = $metadata['unanswered_reason'] ?? null;

        if (! is_string($unansweredReason)
            || preg_match('/\A[a-z0-9_]{1,64}\z/', $unansweredReason) !== 1) {
            $unansweredReason = null;
        }

        $sessionKey = $metadata['session_key'] ?? null;

        if (is_string($sessionKey) && trim($sessionKey) !== '') {
            $sessionKey = hash('sha256', trim($sessionKey));
        } else {
            $sessionKey = null;
        }

        try {
            ChatbotQueryLog::query()->create([
                'status' => $status,
                'top1_similarity' => $top1Similarity,
                'response_mode' => $responseMode,
                'prompt_tokens' => $this->nonNegativeInteger(
                    $metadata['prompt_tokens'] ?? null,
                ),
                'output_tokens' => $this->nonNegativeInteger(
                    $metadata['output_tokens'] ?? null,
                ),
                'total_tokens' => $this->nonNegativeInteger(
                    $metadata['total_tokens'] ?? null,
                ),
                'failure_code' => $failureCode,
                'session_key' => $sessionKey,
                'query_text' => $this->safeText(
                    $metadata['query_text'] ?? null,
                    2000,
                ),
                'normalized_query' => $this->safeText(
                    $metadata['normalized_query'] ?? null,
                    2000,
                ),
                'intent' => is_array($metadata['intent'] ?? null)
                    ? $metadata['intent']
                    : null,
                'filters' => is_array($metadata['filters'] ?? null)
                    ? $metadata['filters']
                    : null,
                'result_count' => min(
                    65535,
                    $this->nonNegativeInteger(
                        $metadata['result_count'] ?? null,
                    ) ?? 0,
                ),
                'is_unanswered' => ($metadata['is_unanswered'] ?? false) === true,
                'unanswered_reason' => $unansweredReason,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Aktivitas chatbot gagal dicatat.', [
                'error_class' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function safeText(mixed $value, int $maximum): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === ''
            ? null
            : mb_substr($value, 0, $maximum, 'UTF-8');
    }
}
