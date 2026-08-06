<?php

namespace App\Contracts\AI;

use App\Data\AI\ChatExplanationRequest;
use App\Data\AI\ChatResult;
use App\Data\AI\ParsedIntentResult;

interface ChatClient
{
    /** @param list<array{role: string, content: string}> $history */
    public function parseIntent(
        string $message,
        array $history = [],
    ): ParsedIntentResult;

    public function explain(ChatExplanationRequest $request): ChatResult;

    public function explainWithWebGrounding(
        ChatExplanationRequest $request,
    ): ChatResult;

    public function provider(): string;

    public function model(): string;

    public function webModel(): string;
}
