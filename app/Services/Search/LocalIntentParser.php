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
    public function __construct(
        private readonly CategoryAliasResolver $categories,
        private readonly ProductFamilyResolver $families,
    ) {}

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
     *     platform: string|null,
     *     mode: string
     *     intent_type: string
     * }
     */
    public function parse(string $message, array $explicit = []): array
    {
        $normalized = Normalizer::text($message);
        $category = $this->categories->resolve($normalized);
        $family = $this->families->resolve($normalized);
        $model = $this->extractModel($normalized);
        $brand = $this->extractBrand($normalized)
            ?? ($family['brand'] ?? null);
        $budget = $this->explicitInteger($explicit, 'budget_max')
            ?? $this->extractBudget($normalized);
        $condition = $this->explicitString($explicit, 'condition')
            ?? $this->extractCondition($normalized);
        $sellerType = $this->explicitString($explicit, 'seller_type')
            ?? $this->extractSellerType($normalized);
        $location = $this->explicitString($explicit, 'location')
            ?? $this->extractLocation($normalized);
        $platform = $this->explicitString($explicit, 'platform')
            ?? $this->extractPlatform($normalized);
        $categoryName = $category?->category->name
            ?? ($family['category'] ?? null);
        $categorySlug = $category?->category->slug
            ?? ($family['category_slug'] ?? null);
        $hasActionableConstraint = $model !== null
            || $brand !== null
            || $budget !== null
            || $condition !== null
            || $sellerType !== null
            || $location !== null
            || $platform !== null;
        $genericCategoryOnly = preg_match(
            '/^(?:saya\s+)?(?:ingin\s+)?(?:cari|carikan|mencari)\s+(?:laptop|handphone|smartphone|router)$/u',
            $normalized,
        ) === 1;
        $ambiguous = $genericCategoryOnly
            || ($categoryName !== null && ! $hasActionableConstraint);

        return [
            'query' => $normalized,
            'product' => $normalized,
            'category' => $categoryName,
            'category_slug' => $categorySlug,
            'category_match_strategy' => $category?->strategy
                ?? ($family === null ? null : 'product_family'),
            'category_matched_alias' => $category?->matchedAlias,
            'brand' => $brand,
            'family' => $family['family'] ?? null,
            'generation' => $family['generation'] ?? null,
            'model_number' => $family['model_number'] ?? null,
            'model' => $model,
            'variant' => $this->extractVariant($normalized),
            'sku_or_part_number' => null,
            'budget_max' => $budget,
            'condition' => $condition,
            'seller_type' => $sellerType,
            'location' => $location,
            'platform' => $platform,
            'mode' => $this->explicitString($explicit, 'mode')
                ?? ($this->containsAny(
                    $normalized,
                    ['reseller', 'jual lagi', 'dijual lagi', 'margin'],
                ) ? 'reseller' : 'buyer'),
            'intent_type' => $this->explicitString(
                $explicit,
                'intent_type',
            ) ?? $this->localIntentType($normalized),
            'needs_clarification' => $ambiguous,
            'clarification_question' => $ambiguous
                ? sprintf(
                    'Anda mencari %s. Sebutkan budget, merek, kondisi baru/bekas, atau model tertentu.',
                    $categoryName ?? 'produk kategori tersebut',
                )
                : null,
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
            '/\b((?:iphone\s*\d{1,2}(?:\s*(?:pro|max|mini|plus))*)|(?:rtx|gtx|rx)\s*\d{3,4}(?:\s*(?:ti|super))?|(?:core\s*)?i[3579][ -]?\d{4,5}[a-z]{0,2}|ryzen\s*[3579]\s*\d{4}[a-z]{0,2})\b/u',
        );
    }

    private function extractVariant(string $text): ?string
    {
        $parts = [];

        if (preg_match('/\b(\d+)\s*(gb|tb)\b/u', $text, $match) === 1) {
            $parts[] = $match[1].strtoupper($match[2]);
        }

        foreach (['ti', 'super', 'laptop', 'pro', 'max', 'mini', 'plus', 'ultra'] as $modifier) {
            if (preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $text) === 1) {
                $parts[] = strtoupper($modifier);
            }
        }

        return $parts === [] ? null : implode(' ', array_unique($parts));
    }

    private function extractCondition(string $text): ?string
    {
        $map = [
            Listing::CONDITION_OPEN_BOX => [
                'open box', 'open-box', 'open_box', 'baru buka',
            ],
            Listing::CONDITION_USED => [
                'bekas', 'secondhand', 'second hand', 'second', 'used',
                'preowned', 'pre-owned', 'pre owned', 'seken',
            ],
            Listing::CONDITION_REFURBISHED => [
                'refurbished', 'refurb', 'rekondisi',
            ],
            Listing::CONDITION_EX_DISPLAY => [
                'ex display', 'ex-display', 'display toko',
            ],
            Listing::CONDITION_NEW => [
                'brand new', 'new', 'baru', 'bnib', 'sealed',
            ],
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
        if ($this->containsAny($text, ['non official', 'non-official'])) {
            return Listing::SELLER_STORE;
        }

        $map = [
            Listing::SELLER_OFFICIAL => [
                'official store', 'seller official', 'toko resmi', 'official',
            ],
            Listing::SELLER_STORE => [
                'seller toko', 'toko biasa', 'toko',
            ],
            Listing::SELLER_RESELLER => [
                'seller reseller', 'pedagang', 'reseller',
            ],
            Listing::SELLER_INDIVIDUAL => [
                'perorangan', 'individual', 'seller pribadi', 'penjual pribadi',
            ],
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
        $matched = preg_match(
            '/(?:maksimal|maximum|maks|max|budget|anggaran|di\s*bawah|dibawah|under|below|less\s+than|<)\s*(?:rp\.?\s*)?([0-9][0-9.,]*)\s*(juta|jt|million|ribu|rb|k|m)?\b/u',
            $text,
            $match,
        ) === 1;

        if (! $matched) {
            $matched = preg_match(
                '/(?:\brp\.?\s*)?([0-9][0-9.,]*)\s*(juta|jt|million)\b/u',
                $text,
                $match,
            ) === 1;
        }

        if (! $matched) {
            $matched = preg_match(
                '/\brp\.?\s*([0-9][0-9.,]*)(m)?\b/u',
                $text,
                $match,
            ) === 1;
        }

        if (! $matched) {
            $matched = preg_match(
                '/\b([0-9][0-9.,]{5,})\b/u',
                $text,
                $match,
            ) === 1;
        }

        if (! $matched) {
            return null;
        }

        $number = str_replace(',', '.', $match[1]);
        $suffix = $match[2] ?? '';

        if (in_array($suffix, ['juta', 'jt', 'million', 'm'], true)) {
            $decimal = substr_count($number, '.') <= 1
                ? (float) $number
                : (float) str_replace('.', '', $number);

            return (int) round($decimal * 1_000_000);
        }

        if (in_array($suffix, ['ribu', 'rb', 'k'], true)) {
            return (int) round((float) $number * 1_000);
        }

        return (int) preg_replace('/\D/', '', $match[1]);
    }

    private function extractBrand(string $text): ?string
    {
        if (preg_match('/\b(iphone|ipad|macbook)\b/u', $text) === 1) {
            return 'apple';
        }

        return $this->firstMatch(
            $text,
            '/\b(asus|acer|amd|apple|corsair|dell|gigabyte|google|hp|huawei|intel|kingston|lenovo|logitech|msi|nvidia|oneplus|oppo|poco|realme|samsung|seagate|sony|vivo|western digital|xiaomi)\b/u',
        );
    }

    private function extractLocation(string $text): ?string
    {
        foreach ((array) config('neomatra.search.known_locations', []) as $location) {
            $normalized = Normalizer::text((string) $location);

            if ($normalized !== '' && preg_match(
                '/(?<![\pL\pN])'.preg_quote($normalized, '/').'(?![\pL\pN])/u',
                $text,
            ) === 1) {
                return (string) $location;
            }
        }

        return null;
    }

    private function extractPlatform(string $text): ?string
    {
        foreach ((array) config('neomatra.search.platform_aliases', []) as $slug => $aliases) {
            foreach ((array) $aliases as $alias) {
                $normalized = Normalizer::text((string) $alias);

                if ($normalized !== '' && preg_match(
                    '/(?<![\pL\pN])'.preg_quote($normalized, '/').'(?![\pL\pN])/u',
                    $text,
                ) === 1) {
                    return (string) $slug;
                }
            }
        }

        return null;
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
            'bandingkan',
            'compare',
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
