<?php

namespace App\Services\Search;

use App\Contracts\AI\ChatClient;
use App\Data\AI\ParsedIntent;
use App\Data\Search\ResolvedIntent;

final class IntentResolver
{
    public function __construct(
        private readonly ChatClient $chat,
        private readonly LocalIntentParser $local,
    ) {}

    /**
     * @param  array<string, mixed>  $explicit
     * @param  list<array{role: string, content: string}>  $history
     */
    public function resolve(
        string $message,
        array $explicit = [],
        array $history = [],
    ): ResolvedIntent {
        $local = $this->local->parse($message, $explicit);
        $result = $this->chat->parseIntent($message, $history);
        $remote = $result->intent?->toArray() ?? [];
        $budgetMax = $this->budgetMax($explicit, $remote, $local);
        $budgetMin = is_int($remote['budget_min'] ?? null)
            ? $remote['budget_min']
            : null;

        if ($budgetMin !== null
            && $budgetMax !== null
            && $budgetMin > $budgetMax) {
            $budgetMin = null;
        }

        $values = [
            'product' => $this->stringValue(
                $remote['product'] ?? null,
                (string) $local['product'],
            ),
            'category' => $this->stringValue(
                $explicit['category'] ?? $local['category'] ?? null,
                $remote['category'] ?? null,
            ),
            'category_slug' => $this->stringValue(
                $explicit['category_slug'] ?? $local['category_slug'] ?? null,
                $remote['category_slug'] ?? null,
            ),
            'brand' => $this->stringValue(
                $local['brand'] ?? null,
                $remote['brand'] ?? null,
            ),
            'family' => $this->stringValue(
                $local['family'] ?? null,
                $remote['family'] ?? null,
            ),
            'generation' => $this->stringValue(
                $local['generation'] ?? null,
                $remote['generation'] ?? null,
            ),
            'model_number' => is_int($local['model_number'] ?? null)
                ? $local['model_number']
                : ($remote['model_number'] ?? null),
            'model' => $this->stringValue(
                $remote['model'] ?? null,
                $local['model'],
            ),
            'variant' => $this->stringValue(
                $remote['variant'] ?? null,
                $local['variant'],
            ),
            'sku_or_part_number' => $this->stringValue(
                $remote['sku_or_part_number'] ?? null,
                $local['sku_or_part_number'],
            ),
            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
            'conditions' => $this->listValue(
                $explicit['condition'] ?? null,
                $remote['conditions'] ?? [],
                $local['condition'],
            ),
            'locations' => $this->listValue(
                $explicit['location'] ?? null,
                $remote['locations'] ?? [],
                $local['location'],
            ),
            'seller_types' => $this->listValue(
                $explicit['seller_type'] ?? null,
                $remote['seller_types'] ?? [],
                $local['seller_type'],
            ),
            'platforms' => $this->listValue(
                $explicit['platform'] ?? null,
                $remote['platforms'] ?? [],
                $local['platform'],
            ),
            'mode' => $this->stringValue(
                $explicit['mode'] ?? null,
                $this->stringValue($remote['mode'] ?? null, $local['mode']),
            ),
            'intent_type' => $this->stringValue(
                $explicit['intent_type'] ?? null,
                $this->stringValue(
                    $remote['intent_type'] ?? null,
                    $local['intent_type'],
                ),
            ),
            'needs_clarification' => (bool) (
                ($local['needs_clarification'] ?? false)
                    || ($remote['needs_clarification'] ?? false)
            ),
            'clarification_question' => $this->stringValue(
                $remote['clarification_question'] ?? null,
                $local['clarification_question'] ?? null,
            ),
        ];

        $intent = ParsedIntent::fromArray($values);

        if (in_array($values['intent_type'] ?? null, [
            ParsedIntent::TYPE_KNOWLEDGE_QUESTION,
            ParsedIntent::TYPE_GENERAL_QUESTION,
        ], true)) {
            $values['needs_clarification'] = false;
            $values['clarification_question'] = null;
            $intent = ParsedIntent::fromArray($values);
        }

        if ($intent === null) {
            $values['needs_clarification'] = false;
            $values['clarification_question'] = null;
            $values['product'] = (string) $local['product'];
            $intent = ParsedIntent::fromArray($values);
        }

        // Local construction uses only values already validated by the request
        // and ParsedIntent enum rules, so this is a programming invariant.
        if ($intent === null) {
            throw new \LogicException('Intent lokal tidak valid.');
        }

        return new ResolvedIntent(
            $intent,
            $result->succeeded(),
            $result->failure,
        );
    }

    /** @param array<string, mixed> $explicit */
    private function budgetMax(
        array $explicit,
        array $remote,
        array $local,
    ): ?int {
        if (array_key_exists('budget_max', $explicit)
            && is_int($explicit['budget_max'])) {
            return $explicit['budget_max'];
        }

        return is_int($remote['budget_max'] ?? null)
            ? $remote['budget_max']
            : $local['budget_max'];
    }

    /** @return list<string> */
    private function listValue(
        mixed $explicit,
        mixed $remote,
        mixed $fallback,
    ): array {
        if (is_string($explicit) && trim($explicit) !== '') {
            return [trim($explicit)];
        }

        if (is_array($remote) && $remote !== []) {
            return array_values($remote);
        }

        return is_string($fallback) && trim($fallback) !== ''
            ? [trim($fallback)]
            : [];
    }

    private function stringValue(mixed $primary, mixed $fallback): ?string
    {
        if (is_string($primary) && trim($primary) !== '') {
            return trim($primary);
        }

        return is_string($fallback) && trim($fallback) !== ''
            ? trim($fallback)
            : null;
    }
}
