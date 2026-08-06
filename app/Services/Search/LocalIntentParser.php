<?php

namespace App\Services\Search;

use App\Data\AI\ParsedIntent;
use App\Helpers\Normalizer;
use App\Models\Listing;

/**
 * A small deterministic fallback for explicit filters. It does not replace the
 * Chat API; it only keeps user-supplied values usable when intent extraction is
 * unavailable or incomplete.
 */
final class LocalIntentParser
{
    /**
     * @return array{
     *     query: string,
     *     product: string|null,
     *     brand: string|null,
     *     model: string|null,
     *     variant: string|null,
     *     sku_or_part_number: string|null,
     *     budget_max: int|null,
     *     condition: string|null,
     *     seller_type: string|null,
     *     location: string|null,
     *     mode: string
     *     intent_type: string
     * }
     */
    public function parse(string $message, array $explicit = []): array
    {
        $normalized = Normalizer::text($message);

        return [
            'query' => $normalized,
            'product' => $normalized,
            'brand' => $this->firstMatch(
                $normalized,
                '/\b(asus|acer|amd|apple|corsair|dell|gigabyte|google|hp|huawei|intel|kingston|lenovo|logitech|msi|nvidia|oneplus|oppo|poco|realme|samsung|seagate|sony|vivo|western digital|xiaomi)\b/u',
            ),
            'model' => $this->extractModel($normalized),
            'variant' => $this->extractVariant($normalized),
            'sku_or_part_number' => null,
            'budget_max' => $this->explicitInteger($explicit, 'budget_max')
                ?? $this->extractBudget($normalized),
            'condition' => $this->explicitString($explicit, 'condition')
                ?? $this->extractCondition($normalized),
            'seller_type' => $this->explicitString($explicit, 'seller_type')
                ?? $this->extractSellerType($normalized),
            'location' => $this->explicitString($explicit, 'location'),
            'mode' => $this->explicitString($explicit, 'mode')
                ?? ($this->containsAny(
                    $normalized,
                    ['reseller', 'jual lagi', 'dijual lagi', 'margin'],
                ) ? 'reseller' : 'buyer'),
            'intent_type' => $this->explicitString(
                $explicit,
                'intent_type',
            ) ?? $this->localIntentType($normalized),
        ];
    }

    /** @param array<string, mixed> $intent */
    public function merge(array $fallback, array $intent): array
    {
        foreach ($fallback as $key => $value) {
            $incoming = $intent[$key] ?? null;

            if (is_string($incoming)) {
                $incoming = trim($incoming);
            }

            if ($incoming !== null && $incoming !== '') {
                $fallback[$key] = $incoming;
            }
        }

        return $fallback;
    }

    private function extractModel(string $text): ?string
    {
        return $this->firstMatch(
            $text,
            '/\b((?:rtx|gtx|rx)\s*\d{3,4}(?:\s*(?:ti|super))?|(?:core\s*)?i[3579][ -]?\d{4,5}[a-z]{0,2}|ryzen\s*[3579]\s*\d{4}[a-z]{0,2})\b/u',
        );
    }

    private function extractVariant(string $text): ?string
    {
        $parts = [];

        if (preg_match('/\b(\d+)\s*(gb|tb)\b/u', $text, $match) === 1) {
            $parts[] = $match[1].strtoupper($match[2]);
        }

        foreach (['ti', 'super', 'laptop'] as $modifier) {
            if (preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $text) === 1) {
                $parts[] = strtoupper($modifier);
            }
        }

        return $parts === [] ? null : implode(' ', array_unique($parts));
    }

    private function extractCondition(string $text): ?string
    {
        $map = [
            Listing::CONDITION_NEW => ['baru', 'bnib', 'sealed'],
            Listing::CONDITION_OPEN_BOX => ['open box', 'baru buka'],
            Listing::CONDITION_USED => ['bekas', 'second', 'used'],
            Listing::CONDITION_REFURBISHED => ['refurbished', 'rekondisi'],
            Listing::CONDITION_EX_DISPLAY => ['ex display', 'display toko'],
        ];

        foreach ($map as $condition => $keywords) {
            if ($this->containsAny($text, $keywords)) {
                return $condition;
            }
        }

        return null;
    }

    private function extractSellerType(string $text): ?string
    {
        $map = [
            Listing::SELLER_OFFICIAL => ['official store', 'seller official'],
            Listing::SELLER_STORE => ['seller toko', 'toko'],
            Listing::SELLER_RESELLER => ['seller reseller'],
            Listing::SELLER_INDIVIDUAL => ['perorangan', 'individual'],
        ];

        foreach ($map as $sellerType => $keywords) {
            if ($this->containsAny($text, $keywords)) {
                return $sellerType;
            }
        }

        return null;
    }

    private function extractBudget(string $text): ?int
    {
        if (preg_match(
            '/(?:maksimal|max|budget|di\s*bawah)\s*(?:rp\.?\s*)?([0-9][0-9.,]*)\s*(juta|jt|ribu|rb|k)?\b/u',
            $text,
            $match,
        ) !== 1) {
            return null;
        }

        $number = str_replace(',', '.', $match[1]);
        $suffix = $match[2] ?? '';

        if (in_array($suffix, ['juta', 'jt'], true)) {
            return (int) round((float) $number * 1_000_000);
        }

        if (in_array($suffix, ['ribu', 'rb', 'k'], true)) {
            return (int) round((float) $number * 1_000);
        }

        return (int) preg_replace('/\D/', '', $match[1]);
    }

    private function looksLikeKnowledgeQuestion(string $text): bool
    {
        return $this->containsAny($text, [
            'apa itu neomatra',
            'apa yang dapat dilakukan neomatra',
            'apa yang bisa dilakukan neomatra',
            'bagaimana neomatra',
            'cara menggunakan neomatra',
            'apakah kondisi dan keamanan',
            'bagaimana menentukan harga pasar',
            'bagaimana menghitung harga pasar',
        ]);
    }

    private function localIntentType(string $text): string
    {
        if ($this->looksLikeKnowledgeQuestion($text)) {
            return ParsedIntent::TYPE_KNOWLEDGE_QUESTION;
        }

        if ($this->containsAny($text, [
            'cari',
            'harga',
            'listing',
            'produk',
            'barang',
            'beli',
            'jual',
            'budget',
            'second',
            'bekas',
            'baru',
        ]) || preg_match('/\d/u', $text) === 1) {
            return ParsedIntent::TYPE_PRODUCT_SEARCH;
        }

        if (preg_match(
            '/^(apa|apakah|siapa|kapan|mengapa|kenapa|bagaimana|jelaskan|what|who|when|why|how|halo|hai|hello)\b/u',
            $text,
        ) === 1) {
            return ParsedIntent::TYPE_GENERAL_QUESTION;
        }

        return ParsedIntent::TYPE_PRODUCT_SEARCH;
    }

    private function firstMatch(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $match) === 1
            ? trim($match[1])
            : null;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $values */
    private function explicitString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /** @param array<string, mixed> $values */
    private function explicitInteger(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
