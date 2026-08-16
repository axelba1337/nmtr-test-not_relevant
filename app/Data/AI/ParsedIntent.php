<?php

namespace App\Data\AI;

final readonly class ParsedIntent
{
    public const TYPE_PRODUCT_SEARCH = 'product_search';

    public const TYPE_KNOWLEDGE_QUESTION = 'knowledge_question';

    public const TYPE_GENERAL_QUESTION = 'general_question';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_PRODUCT_SEARCH,
        self::TYPE_KNOWLEDGE_QUESTION,
        self::TYPE_GENERAL_QUESTION,
    ];

    /** @var list<string> */
    public const CONDITIONS = [
        'new',
        'open_box',
        'used',
        'refurbished',
        'ex_display',
        'unknown',
    ];

    /** @var list<string> */
    public const SELLER_TYPES = [
        'official',
        'store',
        'reseller',
        'individual',
        'unknown',
    ];

    /** @var list<string> */
    public const MODES = ['buyer', 'reseller'];

    /**
     * @param  list<string>  $conditions
     * @param  list<string>  $locations
     * @param  list<string>  $sellerTypes
     * @param  list<string>  $platforms
     */
    public function __construct(
        public string $product,
        public ?string $category,
        public ?string $brand,
        public ?string $model,
        public ?string $variant,
        public ?string $skuOrPartNumber,
        public ?int $budgetMin,
        public ?int $budgetMax,
        public array $conditions,
        public array $locations,
        public array $sellerTypes,
        public string $mode,
        public bool $needsClarification,
        public ?string $clarificationQuestion,
        public string $intentType = self::TYPE_PRODUCT_SEARCH,
        public array $platforms = [],
    ) {}

    public static function fromArray(array $value): ?self
    {
        $needsClarification = $value['needs_clarification'] ?? null;

        if (! is_bool($needsClarification)) {
            return null;
        }

        $intentType = $value['intent_type'] ?? self::TYPE_PRODUCT_SEARCH;

        if (! is_string($intentType)
            || ! in_array($intentType, self::TYPES, true)) {
            return null;
        }

        $product = self::requiredString($value['product'] ?? null, 300, true);
        $clarification = self::nullableString(
            $value['clarification_question'] ?? null,
            500,
        );

        if (! self::validOptionalStrings($value, [
            'category' => 120,
            'brand' => 120,
            'model' => 160,
            'variant' => 200,
            'sku_or_part_number' => 160,
            'clarification_question' => 500,
        ])
            || $product === null
            || ($intentType === self::TYPE_PRODUCT_SEARCH
                && ! $needsClarification
                && $product === '')
            || ($needsClarification && $clarification === null)) {
            return null;
        }

        $budgetMin = self::nullableNonNegativeInteger(
            $value['budget_min'] ?? null,
        );
        $budgetMax = self::nullableNonNegativeInteger(
            $value['budget_max'] ?? null,
        );

        if (($value['budget_min'] ?? null) !== null && $budgetMin === null
            || ($value['budget_max'] ?? null) !== null && $budgetMax === null
            || ($budgetMin !== null && $budgetMax !== null
                && $budgetMin > $budgetMax)) {
            return null;
        }

        $conditions = self::enumList(
            $value['conditions'] ?? [],
            self::CONDITIONS,
        );
        $sellerTypes = self::enumList(
            $value['seller_types'] ?? [],
            self::SELLER_TYPES,
        );
        $locations = self::stringList($value['locations'] ?? [], 120);
        $platforms = self::stringList($value['platforms'] ?? [], 120);
        $mode = $value['mode'] ?? null;

        if ($conditions === null
            || $sellerTypes === null
            || $locations === null
            || $platforms === null
            || ! is_string($mode)
            || ! in_array($mode, self::MODES, true)) {
            return null;
        }

        return new self(
            $product,
            self::nullableString($value['category'] ?? null, 120),
            self::nullableString($value['brand'] ?? null, 120),
            self::nullableString($value['model'] ?? null, 160),
            self::nullableString($value['variant'] ?? null, 200),
            self::nullableString($value['sku_or_part_number'] ?? null, 160),
            $budgetMin,
            $budgetMax,
            $conditions,
            $locations,
            $sellerTypes,
            $mode,
            $needsClarification,
            $clarification,
            $intentType,
            $platforms,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product' => $this->product,
            'category' => $this->category,
            'brand' => $this->brand,
            'model' => $this->model,
            'variant' => $this->variant,
            'sku_or_part_number' => $this->skuOrPartNumber,
            'budget_min' => $this->budgetMin,
            'budget_max' => $this->budgetMax,
            'conditions' => $this->conditions,
            'locations' => $this->locations,
            'seller_types' => $this->sellerTypes,
            'platforms' => $this->platforms,
            'mode' => $this->mode,
            'needs_clarification' => $this->needsClarification,
            'clarification_question' => $this->clarificationQuestion,
            'intent_type' => $this->intentType,
        ];
    }

    private static function requiredString(
        mixed $value,
        int $maxLength,
        bool $allowEmpty = false,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ((! $allowEmpty && $value === '')
            || mb_strlen($value, 'UTF-8') > $maxLength) {
            return null;
        }

        return $value;
    }

    private static function nullableString(
        mixed $value,
        int $maxLength,
    ): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredString($value, $maxLength);
    }

    private static function nullableNonNegativeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)
            && is_finite($value)
            && floor($value) === $value
            && $value >= 0
            && $value <= PHP_INT_MAX) {
            return (int) $value;
        }

        return null;
    }

    /** @param array<string, int> $fields */
    private static function validOptionalStrings(
        array $value,
        array $fields,
    ): bool {
        foreach ($fields as $key => $maxLength) {
            if (array_key_exists($key, $value)
                && $value[$key] !== null
                && $value[$key] !== ''
                && self::nullableString($value[$key], $maxLength) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>|null
     */
    private static function enumList(mixed $values, array $allowed): ?array
    {
        $values = self::stringList($values, 120);

        if ($values === null) {
            return null;
        }

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                return null;
            }
        }

        return $values;
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $values, int $maxLength): ?array
    {
        if (! is_array($values) || count($values) > 20) {
            return null;
        }

        $result = [];

        foreach (array_values($values) as $value) {
            $normalized = self::requiredString($value, $maxLength);

            if ($normalized === null) {
                return null;
            }

            $result[$normalized] = $normalized;
        }

        return array_values($result);
    }
}
