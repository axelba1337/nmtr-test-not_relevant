<?php

namespace App\Data\AI;

final readonly class ChatExplanationRequest
{
    /**
     * All numeric facts in these arrays must already have been calculated by
     * the backend. They are data for explanation, never instructions.
     *
     * @param  list<array<string, mixed>>  $results
     * @param  list<array<string, mixed>>  $knowledge
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(
        public string $question,
        public ParsedIntent $intent,
        public array $results,
        public array $knowledge = [],
        public array $history = [],
    ) {}

    /** @return array<string, mixed> */
    public function backendContext(): array
    {
        return [
            'intent' => $this->intent->toArray(),
            'results' => array_values($this->results),
            'knowledge' => array_values($this->knowledge),
        ];
    }
}
