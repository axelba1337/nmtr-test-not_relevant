<?php

namespace Database\Seeders;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductAlias;
use Database\Seeders\Support\SqlDatasetParser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NeomatraDatasetV3Seeder extends Seeder
{
    private const SOURCE_NAME = 'Neomatra Dataset & Taxonomy V3';

    private const OBSOLETE_KNOWLEDGE = [
        'Tentang Semantic Chatbot Lite',
        'Cara chatbot menggunakan knowledge',
        'Ketika informasi tidak tersedia',
    ];

    private const LEGACY_V2_PRODUCT_KEYS = [
        'asus|va24ehf|23.8-inch fhd 100hz||asus va24ehf',
    ];

    public function run(): void
    {
        $parser = new SqlDatasetParser;

        DB::transaction(function () use ($parser): void {
            $this->seedPlatforms($parser->rows(
                database_path('data/v3/platforms_v3_expanded.sql'),
                'INSERT INTO `platforms`',
                'ON DUPLICATE KEY UPDATE',
            ));
            $this->deactivateLegacyDemoListings();
            $this->seedProducts($parser->rows(
                database_path('data/v3/products_v3_expanded.sql'),
                'INSERT INTO `products`',
                'ON DUPLICATE KEY UPDATE',
            ));
            $this->seedKnowledge($parser->rows(
                database_path('data/v3/knowledge_items_v3_expanded.sql'),
                'INSERT INTO `_neomatra_knowledge_seed`',
                ';',
            ));
        });
    }

    private function deactivateLegacyDemoListings(): void
    {
        Listing::query()
            ->where('is_active', true)
            ->whereHas('platform', fn ($query) => $query->whereIn(
                'slug',
                ['tokopedia', 'shopee', 'olx'],
            ))
            ->get()
            ->filter(fn (Listing $listing): bool => (bool) (
                $listing->raw_payload['demo'] ?? false
            ))
            ->each->update(['is_active' => false]);
    }

    /** @param list<list<mixed>> $rows */
    private function seedPlatforms(array $rows): void
    {
        foreach ($rows as $row) {
            $this->assertColumnCount($row, 12, 'platform');

            Platform::query()->updateOrCreate(
                ['slug' => (string) $row[1]],
                [
                    'name' => (string) $row[0],
                    'source_type' => (string) $row[2],
                    'access_type' => (string) $row[3],
                    'connector' => $this->nullableString($row[4]),
                    'base_url' => $this->nullableString($row[5]),
                    'is_active' => (bool) $row[6],
                    'sync_enabled' => (bool) $row[7],
                    'status' => (string) $row[8],
                ],
            );
        }
    }

    /** @param list<list<mixed>> $rows */
    private function seedProducts(array $rows): void
    {
        $categoryIds = Category::query()->pluck('id', 'slug');
        $nameCounts = [];
        $modelCounts = [];

        foreach ($rows as $row) {
            $brand = $this->nullableString($row[2]);
            $model = $this->nullableString($row[3]);
            $nameKey = $this->identityKey($brand, (string) $row[1]);
            $modelKey = $this->identityKey($brand, $model);
            $nameCounts[$nameKey] = ($nameCounts[$nameKey] ?? 0) + 1;

            if ($model !== null) {
                $modelCounts[$modelKey] = ($modelCounts[$modelKey] ?? 0) + 1;
            }
        }

        $this->retireLegacyV2Products();

        foreach ($rows as $row) {
            $this->assertColumnCount($row, 20, 'product');
            $categorySlug = $this->categorySlug((string) $row[0]);
            $categoryId = $categoryIds->get($categorySlug);

            if (! is_int($categoryId)) {
                throw new RuntimeException(
                    "Kategori V3 tidak ditemukan: {$categorySlug}",
                );
            }

            $name = (string) $row[1];
            $brand = $this->nullableString($row[2]);
            $model = $this->nullableString($row[3]);
            $variant = $this->nullableString($row[4]);
            $sku = $this->nullableString($row[5]);
            $normalizedKey = Product::makeNormalizedKey(
                $name,
                $brand,
                $model,
                $variant,
                $sku,
            );

            if ($normalizedKey !== (string) $row[6]) {
                throw new RuntimeException(
                    "Normalized key V3 tidak konsisten: {$name}",
                );
            }

            $attributes = json_decode(
                (string) $row[7],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($attributes)) {
                throw new RuntimeException(
                    "Attributes V3 tidak valid: {$name}",
                );
            }

            $product = $this->canonicalProduct(
                $name,
                $brand,
                $model,
                $normalizedKey,
                ($nameCounts[$this->identityKey($brand, $name)] ?? 0) === 1,
                ($modelCounts[$this->identityKey($brand, $model)] ?? 0) === 1,
            );
            $payload = [
                'category_id' => $categoryId,
                'name' => $name,
                'brand' => $brand,
                'model' => $model,
                'variant' => $variant,
                'sku_or_part_number' => $sku,
                'attributes' => $attributes,
                'spec_source_name' => $this->nullableString($row[8]),
                'spec_source_url' => $this->nullableString($row[9]),
                'spec_source_checked_at' => $this->nullableString($row[10]),
                'is_active' => (bool) $row[17],
            ];
            $semanticChanged = $product !== null
                && $this->productSemanticsChanged($product, $payload);

            if ($product !== null
                && $product->normalized_key !== $normalizedKey) {
                $product->forceFill(['normalized_key' => $normalizedKey]);
                $product->save();
            }

            $product = Product::query()->updateOrCreate(
                ['normalized_key' => $normalizedKey],
                $payload,
            );

            if ($semanticChanged) {
                $product->forceFill([
                    'embedding_hash' => null,
                    'embedding_error' => null,
                ])->save();
            }

            $this->mergeDuplicateProducts(
                $product,
                $name,
                $brand,
                $model,
                ($nameCounts[$this->identityKey($brand, $name)] ?? 0) === 1,
                ($modelCounts[$this->identityKey($brand, $model)] ?? 0) === 1,
            );
            $this->seedAliases($product, $categorySlug);
        }
    }

    /** @param list<list<mixed>> $rows */
    private function seedKnowledge(array $rows): void
    {
        KnowledgeItem::query()
            ->whereIn('title', self::OBSOLETE_KNOWLEDGE)
            ->update(['is_active' => false]);

        $categoryId = Category::query()
            ->where('slug', 'panduan-neomatra')
            ->value('id');

        if (! is_int($categoryId)) {
            throw new RuntimeException(
                'Kategori panduan-neomatra tidak ditemukan.',
            );
        }

        foreach ($rows as $row) {
            $this->assertColumnCount($row, 5, 'knowledge');
            $keywords = json_decode(
                (string) $row[2],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_array($keywords)) {
                throw new RuntimeException(
                    'Keywords V3 tidak valid: '.(string) $row[0],
                );
            }

            $title = (string) $row[0];
            $existing = KnowledgeItem::query()
                ->where('title', $title)
                ->first();
            $payload = [
                'content' => (string) $row[1],
                'category_id' => $categoryId,
                'keywords' => array_values($keywords),
                'source_name' => self::SOURCE_NAME,
                'source_url' => null,
                'is_active' => true,
                'is_featured' => (bool) $row[3],
                'sort_order' => (int) $row[4],
            ];
            $semanticChanged = $existing !== null
                && $this->knowledgeSemanticsChanged($existing, $payload);
            $item = KnowledgeItem::query()->updateOrCreate(
                ['title' => $title],
                $payload,
            );

            if ($semanticChanged) {
                $item->forceFill([
                    'embedding_hash' => null,
                    'embedding_error' => null,
                ])->save();
            }
        }
    }

    private function canonicalProduct(
        string $name,
        ?string $brand,
        ?string $model,
        string $normalizedKey,
        bool $uniqueName,
        bool $uniqueModel,
    ): ?Product {
        $product = Product::query()
            ->where('normalized_key', $normalizedKey)
            ->first();

        if ($product !== null) {
            return $product;
        }

        if ($uniqueName) {
            $product = Product::query()
                ->where('name', $name)
                ->where('brand', $brand)
                ->oldest('id')
                ->first();

            if ($product !== null) {
                return $product;
            }
        }

        if ($uniqueModel && $model !== null) {
            return Product::query()
                ->where('brand', $brand)
                ->where('model', $model)
                ->oldest('id')
                ->first();
        }

        return null;
    }

    private function retireLegacyV2Products(): void
    {
        $products = Product::query()
            ->whereIn('normalized_key', self::LEGACY_V2_PRODUCT_KEYS)
            ->get();

        foreach ($products as $product) {
            $product->listings()->update(['product_id' => null]);
            $product->delete();
        }
    }

    private function mergeDuplicateProducts(
        Product $canonical,
        string $name,
        ?string $brand,
        ?string $model,
        bool $uniqueName,
        bool $uniqueModel,
    ): void {
        if (! $uniqueName && ! $uniqueModel) {
            return;
        }

        $duplicates = Product::query()
            ->whereKeyNot($canonical->getKey())
            ->where(function ($query) use (
                $name,
                $brand,
                $model,
                $uniqueName,
                $uniqueModel,
            ): void {
                if ($uniqueName) {
                    $query->where(function ($nameQuery) use (
                        $name,
                        $brand,
                    ): void {
                        $nameQuery
                            ->where('name', $name)
                            ->where('brand', $brand);
                    });
                }

                if ($uniqueModel && $model !== null) {
                    $method = $uniqueName ? 'orWhere' : 'where';
                    $query->{$method}(function ($modelQuery) use (
                        $brand,
                        $model,
                    ): void {
                        $modelQuery
                            ->where('brand', $brand)
                            ->where('model', $model);
                    });
                }
            })
            ->get();

        foreach ($duplicates as $duplicate) {
            foreach ($duplicate->aliases()->get() as $alias) {
                ProductAlias::query()->updateOrCreate(
                    [
                        'product_id' => $canonical->id,
                        'normalized_alias' => $alias->normalized_alias,
                    ],
                    ['alias' => $alias->alias],
                );
            }

            $duplicate->listings()->update(['product_id' => $canonical->id]);
            $duplicate->delete();
        }
    }

    private function seedAliases(Product $product, string $categorySlug): void
    {
        $aliases = $this->aliasCandidates($product, $categorySlug);

        if (count($aliases) < 3) {
            throw new RuntimeException(
                "Alias V3 kurang dari tiga: {$product->name}",
            );
        }

        foreach ($aliases as $normalizedAlias => $alias) {
            $existing = ProductAlias::query()
                ->where('product_id', $product->id)
                ->where('normalized_alias', $normalizedAlias)
                ->first();
            $semanticChanged = $existing !== null
                && $existing->alias !== $alias;
            $record = ProductAlias::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'normalized_alias' => $normalizedAlias,
                ],
                ['alias' => $alias],
            );

            if ($semanticChanged) {
                $record->forceFill([
                    'embedding_hash' => null,
                    'embedding_error' => null,
                ])->save();
            }
        }
    }

    /** @return array<string, string> */
    private function aliasCandidates(
        Product $product,
        string $categorySlug,
    ): array {
        $model = trim((string) $product->model);
        $brand = trim((string) $product->brand);
        $variant = trim((string) $product->variant);
        $sku = trim((string) $product->sku_or_part_number);
        $prefix = match ($categorySlug) {
            'gpu' => 'VGA',
            'cpu' => 'Processor',
            'ram' => 'RAM',
            'ssd' => 'SSD',
            'hdd' => 'HDD',
            'motherboard' => 'Motherboard',
            'psu' => 'PSU',
            'cpu-cooler' => 'CPU Cooler',
            'smartphone' => 'HP',
            'gaming-laptop', 'business-laptop', 'consumer-laptop' => 'Laptop',
            'monitor' => 'Monitor',
            'keyboard' => 'Keyboard',
            'mouse' => 'Mouse',
            'headset' => 'Headset',
            'webcam' => 'Webcam',
            'keyboard-mouse-combo' => 'Keyboard Mouse Combo',
            'router' => 'Router',
            'access-point' => 'Access Point',
            'switch' => 'Switch',
            'modem' => 'Modem',
            'network-adapter' => 'Network Adapter',
            default => 'Produk',
        };
        $compactModel = preg_replace('/[\s\-_]+/u', '', $model) ?: '';
        $candidates = [
            (string) $product->name,
            $model,
            trim("{$brand} {$model}"),
            trim("{$model} {$variant}"),
            trim("{$brand} {$model} {$variant}"),
            $compactModel,
            $sku,
            trim("{$prefix} {$model} {$variant}"),
        ];
        $aliases = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            $normalized = Normalizer::text($candidate);

            if ($candidate !== '' && $normalized !== '') {
                $aliases[$normalized] = $candidate;
            }
        }

        return $aliases;
    }

    /** @param array<string, mixed> $payload */
    private function productSemanticsChanged(
        Product $product,
        array $payload,
    ): bool {
        return (int) $product->category_id !== (int) $payload['category_id']
            || $product->name !== $payload['name']
            || $product->brand !== $payload['brand']
            || $product->model !== $payload['model']
            || $product->variant !== $payload['variant']
            || $product->sku_or_part_number !== $payload['sku_or_part_number']
            || $product->attributes !== $payload['attributes'];
    }

    /** @param array<string, mixed> $payload */
    private function knowledgeSemanticsChanged(
        KnowledgeItem $item,
        array $payload,
    ): bool {
        return $item->content !== $payload['content']
            || (int) $item->category_id !== (int) $payload['category_id']
            || $item->keywords !== $payload['keywords']
            || $item->source_name !== $payload['source_name'];
    }

    /** @param list<mixed> $row */
    private function assertColumnCount(
        array $row,
        int $expected,
        string $entity,
    ): void {
        if (count($row) !== $expected) {
            throw new RuntimeException(sprintf(
                'Kolom %s V3 tidak valid: expected=%d actual=%d',
                $entity,
                $expected,
                count($row),
            ));
        }
    }

    private function categorySlug(string $variable): string
    {
        if (! str_starts_with($variable, '@cat_')) {
            throw new RuntimeException(
                "Referensi kategori V3 tidak valid: {$variable}",
            );
        }

        return str_replace('_', '-', substr($variable, 5));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function identityKey(?string $brand, ?string $value): string
    {
        return Normalizer::text((string) $brand)
            .'|'.Normalizer::text((string) $value);
    }
}
