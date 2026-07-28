<?php

namespace App\Services;

use App\Models\ChatbotQueryLog;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class ChatbotActivityLogger
{
    public function record(
        string $status,
        ?float $top1Similarity = null,
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

        try {
            ChatbotQueryLog::query()->create([
                'status' => $status,
                'top1_similarity' => $top1Similarity,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Aktivitas chatbot gagal dicatat.', [
                'error_class' => $exception::class,
            ]);

            return false;
        }

        return true;
    }
}
