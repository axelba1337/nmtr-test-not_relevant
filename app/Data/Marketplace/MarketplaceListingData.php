<?php

namespace App\Data\Marketplace;

use App\Models\Listing;
use App\Services\Neomatra\ConditionNormalizer;
use App\Services\Neomatra\SellerTypeNormalizer;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final readonly class MarketplaceListingData
{
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
    public const STOCK_STATUSES = [
        'in_stock',
        'out_of_stock',
        'unknown',
    ];

    /** @var list<string> */
    public const PRICE_TYPES = [
        'full_price',
        'installment',
        'down_payment',
        'unknown',
    ];

    public function __construct(
        public string $platformSlug,
        public ?string $productReference,
        public ?string $productBrand,
        public ?string $productModel,
        public ?string $productVariant,
        public ?string $productSku,
        public ?string $externalListingId,
        public string $title,
        public ?string $description,
        public int $price,
        public string $currency,
        public string $condition,
        public string $conditionSource,
        public ?string $sellerName,
        public string $sellerType,
        public ?string $location,
        public string $productUrl,
        public ?string $imageUrl,
        public string $stockStatus,
        public string $priceType,
        public ?int $shippingCost,
        public ?int $serviceFee,
        public ?int $inspectionCost,
        public ?int $repairEstimate,
        public ?string $warranty,
        public ?string $sourcePublishedAt,
    ) {}

    public static function fromArray(
        string $platformSlug,
        array $value,
    ): ?self {
        $platformSlug = strtolower(trim($platformSlug));
        $title = self::string($value['title'] ?? null, 255);
        $price = self::money($value['price'] ?? null, true);
        $productUrl = self::httpUrl($value['product_url'] ?? null, false);
        $currencyValue = $value['currency'] ?? 'IDR';
        $currency = is_string($currencyValue)
            ? strtoupper(trim($currencyValue))
            : '';

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $platformSlug)
            || $title === null
            || $price === null
            || $productUrl === null
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        $description = self::nullableString(
            $value['description'] ?? null,
            10000,
        );
        $externalId = self::nullableString(
            $value['external_listing_id'] ?? null,
            255,
        );
        $imageUrl = self::httpUrl($value['image_url'] ?? null, true);
        $sourcePublishedAt = self::dateTime(
            $value['source_published_at'] ?? null,
        );

        if (! self::validOptionalString($value, 'product_reference', 500)
            || ! self::validOptionalString($value, 'product_brand', 120)
            || ! self::validOptionalString($value, 'product_model', 160)
            || ! self::validOptionalString($value, 'product_variant', 200)
            || ! self::validOptionalString($value, 'product_sku', 160)
            || ! self::validOptionalString($value, 'description', 10000)
            || ! self::validOptionalString($value, 'external_listing_id', 255)
            || ! self::validOptionalString($value, 'seller_name', 255)
            || ! self::validOptionalString($value, 'location', 255)
            || ! self::validOptionalString($value, 'warranty', 255)
            || (($value['image_url'] ?? null) !== null && $imageUrl === null)
            || (($value['source_published_at'] ?? null) !== null
                && $sourcePublishedAt === null)) {
            return null;
        }

        $shippingCost = self::nullableMoney($value['shipping_cost'] ?? null);
        $serviceFee = self::nullableMoney($value['service_fee'] ?? null);
        $inspectionCost = self::nullableMoney(
            $value['inspection_cost'] ?? null,
        );
        $repairEstimate = self::nullableMoney(
            $value['repair_estimate'] ?? null,
        );

        foreach ([
            'shipping_cost' => $shippingCost,
            'service_fee' => $serviceFee,
            'inspection_cost' => $inspectionCost,
            'repair_estimate' => $repairEstimate,
        ] as $key => $normalized) {
            if (($value[$key] ?? null) !== null && $normalized === null) {
                return null;
            }
        }

        if (array_key_exists('condition', $value)
            && ! is_string($value['condition'])) {
            return null;
        }

        if ((array_key_exists('seller_type', $value)
                && ! is_string($value['seller_type']))
            || (array_key_exists('stock_status', $value)
                && ! is_string($value['stock_status']))
            || (array_key_exists('price_type', $value)
                && ! is_string($value['price_type']))) {
            return null;
        }

        $conditionText = $value['condition']
            ?? trim($title.' '.($description ?? ''));
        $condition = (new ConditionNormalizer)->normalize($conditionText);
        $sellerType = (new SellerTypeNormalizer)->normalize(
            (string) ($value['seller_type'] ?? 'unknown'),
        );
        $stockStatus = self::normalizeStockStatus(
            (string) ($value['stock_status'] ?? 'unknown'),
        );
        $priceType = self::normalizePriceType(
            (string) ($value['price_type'] ?? 'full_price'),
        );
        $conditionSource = 'seller_description';

        return new self(
            $platformSlug,
            self::nullableString($value['product_reference'] ?? null, 500),
            self::nullableString($value['product_brand'] ?? null, 120),
            self::nullableString($value['product_model'] ?? null, 160),
            self::nullableString($value['product_variant'] ?? null, 200),
            self::nullableString($value['product_sku'] ?? null, 160),
            $externalId,
            $title,
            $description,
            $price,
            $currency,
            $condition,
            $conditionSource,
            self::nullableString($value['seller_name'] ?? null, 255),
            $sellerType,
            self::nullableString($value['location'] ?? null, 255),
            $productUrl,
            $imageUrl,
            $stockStatus,
            $priceType,
            $shippingCost,
            $serviceFee,
            $inspectionCost,
            $repairEstimate,
            self::nullableString($value['warranty'] ?? null, 255),
            $sourcePublishedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toStorageArray(): array
    {
        return [
            'external_listing_id' => $this->externalListingId,
            'title' => $this->title,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'condition' => $this->condition,
            'condition_source' => $this->conditionSource,
            'seller_name' => $this->sellerName,
            'seller_type' => $this->sellerType,
            'location' => $this->location,
            'product_url' => $this->productUrl,
            'image_url' => $this->imageUrl,
            'stock_status' => $this->stockStatus,
            'price_type' => $this->priceType,
            'shipping_cost' => $this->shippingCost,
            'service_fee' => $this->serviceFee,
            'inspection_cost' => $this->inspectionCost,
            'repair_estimate' => $this->repairEstimate,
            'warranty' => $this->warranty,
            'source_published_at' => $this->sourcePublishedAt,
        ];
    }

    public function hasProductHints(): bool
    {
        return $this->productReference !== null
            || $this->productBrand !== null
            || $this->productModel !== null
            || $this->productVariant !== null
            || $this->productSku !== null;
    }

    private static function normalizeStockStatus(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return match ($value) {
            'in_stock', 'available', 'ready', 'tersedia' => 'in_stock',
            'out_of_stock', 'sold', 'habis', 'tidak tersedia' => 'out_of_stock',
            default => 'unknown',
        };
    }

    private static function normalizePriceType(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return match ($value) {
            'full_price', 'full', 'cash', 'harga penuh' => 'full_price',
            'installment', 'cicilan' => 'installment',
            'down_payment', 'dp', 'uang muka' => 'down_payment',
            default => 'unknown',
        };
    }

    private static function string(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && mb_strlen($value, 'UTF-8') <= $maxLength
            ? $value
            : null;
    }

    private static function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::string($value, $maxLength);
    }

    private static function validOptionalString(
        array $value,
        string $key,
        int $maxLength,
    ): bool {
        return ! array_key_exists($key, $value)
            || $value[$key] === null
            || $value[$key] === ''
            || self::string($value[$key], $maxLength) !== null;
    }

    private static function money(
        mixed $value,
        bool $positive = false,
    ): ?int {
        $minimum = $positive ? 1 : 0;

        if (is_int($value)) {
            return $value >= $minimum && $value <= Listing::MAX_MONEY
                ? $value
                : null;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            $number = filter_var($value, FILTER_VALIDATE_INT);

            return is_int($number)
                && $number >= $minimum
                && $number <= Listing::MAX_MONEY
                    ? $number
                    : null;
        }

        if (is_float($value)
            && is_finite($value)
            && floor($value) === $value
            && $value >= $minimum
            && $value <= Listing::MAX_MONEY) {
            return (int) $value;
        }

        return null;
    }

    private static function nullableMoney(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::money($value);
    }

    private static function httpUrl(mixed $value, bool $nullable): ?string
    {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return mb_strlen($value, 'UTF-8') <= 2048
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
                ? $value
                : null;
    }

    private static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! $value instanceof DateTimeInterface) {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value);

            return $date->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
