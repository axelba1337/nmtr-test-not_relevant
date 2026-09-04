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
    private const MVP_PLATFORM_SLUGS = [
        'tokopedia',
        'shopee',
        'olx',
        'facebook',
        'carousell',
    ];

    private const SOURCE_NAME = 'Neomatra Dataset & Taxonomy V3';

    private const OBSOLETE_KNOWLEDGE = [
        'Tentang Semantic Chatbot Lite',
        'Cara chatbot menggunakan knowledge',
        'Ketika informasi tidak tersedia',
        'Bagaimana Neomatra menentukan harga pasar?',
    ];

    private const LEGACY_MVP_KNOWLEDGE = [
        'Seller type Neomatra / Neomatra seller types',
        'Arti official seller / Meaning of official seller',
        'Harga pasar / Market price',
        'Comparable listings',
        'Persentase undervalued / Undervalued percentage',
        'Total cost',
        'Estimasi margin reseller / Reseller margin estimate',
        'Risk signal',
        'Perbandingan dua produk / Comparing two products',
        'Kualitas sample market price / Market sample quality',
    ];

    private const LEGACY_V2_PRODUCT_KEYS = [
        'asus|va24ehf|23.8-inch fhd 100hz||asus va24ehf',
    ];

    public function run(): void
    {
        $parser = new SqlDatasetParser;

        DB::transaction(function () use ($parser): void {
            $this->migrateLegacyPlatformSlugs();
            $this->seedPlatforms($parser->rows(
                database_path('data/v3/platforms_v3_expanded.sql'),
                'INSERT INTO `platforms`',
                'ON DUPLICATE KEY UPDATE',
            ));
            $this->cleanPlatformRegistry();
            $this->removeProvenDemoListings();
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

    private function migrateLegacyPlatformSlugs(): void
    {
        $legacy = Platform::query()
            ->where('slug', 'facebook-marketplace')
            ->first();

        if ($legacy === null) {
            return;
        }

        $facebook = Platform::query()->where('slug', 'facebook')->first();

        if ($facebook === null) {
            $legacy->forceFill(['slug' => 'facebook'])->save();

            return;
        }

        $legacy->listings()->update(['platform_id' => $facebook->id]);
        $legacy->syncRuns()->update(['platform_id' => $facebook->id]);
        $legacy->delete();
    }

    private function cleanPlatformRegistry(): void
    {
        foreach (Platform::query()->whereNotIn('slug', self::MVP_PLATFORM_SLUGS)->get() as $platform) {
            if ($platform->listings()->exists() || $platform->syncRuns()->exists()) {
                $platform->forceFill([
                    'is_active' => false,
                    'sync_enabled' => false,
                    'status' => Platform::STATUS_DISABLED,
                ])->save();

                continue;
            }

            $platform->delete();
        }
    }

    private function removeProvenDemoListings(): void
    {
        foreach (Listing::query()->lazyById(100) as $listing) {
            $payload = is_array($listing->raw_payload)
                ? $listing->raw_payload
                : [];
            $connector = strtolower((string) ($payload['connector'] ?? ''));
            $isDemo = ($payload['demo'] ?? false) === true
                || str_contains($connector, 'demo')
                || str_contains((string) $listing->product_url, 'example.test')
                || str_contains(strtolower((string) $listing->title), 'data demo')
                || str_contains(strtolower((string) $listing->description), 'bukan penawaran marketplace nyata');

            if ($isDemo) {
                $listing->delete();
            }
        }
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
                'content' => $this->currentMvpKnowledgeContent(
                    $title,
                    (string) $row[1],
                ),
                'category_id' => $categoryId,
                'keywords' => array_values($keywords),
                'source_name' => self::SOURCE_NAME,
                'source_url' => null,
                'is_active' => ! in_array(
                    $title,
                    self::LEGACY_MVP_KNOWLEDGE,
                    true,
                ),
                'is_featured' => ! in_array(
                    $title,
                    self::LEGACY_MVP_KNOWLEDGE,
                    true,
                ) && (bool) $row[3],
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

    private function currentMvpKnowledgeContent(
        string $title,
        string $fallback,
    ): string {
        return match ($title) {
            'Tentang Neomatra / About Neomatra' => 'ID: Neomatra adalah aplikasi pencarian produk teknologi yang menggabungkan semantic search, canonical product, alias, dan listing marketplace. Hasil menampilkan harga listing aktual, kondisi, lokasi, sumber, dan freshness tanpa menghitung harga pasar atau keuntungan. EN: Neomatra is a technology-product search application combining semantic search, canonical products, aliases, and marketplace listings. Results show actual listing price, condition, location, source, and freshness without calculating market price or profit.',
            'Barang baru dan bekas / New and used items' => 'ID: Neomatra mempertahankan kondisi listing seperti new, used, open_box, refurbished, ex_display, dan unknown agar pengguna dapat memfilter hasil secara transparan. EN: Neomatra preserves listing conditions such as new, used, open_box, refurbished, ex_display, and unknown so users can filter results transparently.',
            'Filter terlalu ketat / Filters too strict' => 'ID: Jika produk valid tetapi kombinasi budget, lokasi, kondisi, atau platform menghasilkan nol listing, sistem menandai FILTER_TOO_STRICT dan dapat melonggarkan filter opsional secara bertahap. EN: If a product is valid but a combination of budget, location, condition, or platform returns zero listings, the system marks FILTER_TOO_STRICT and may progressively relax optional filters.',
            'Mengapa ranking berbeda / Why rankings differ' => 'ID: Ranking dapat berbeda karena semantic relevance, harga listing aktual, kondisi, kecocokan lokasi yang diminta, dan freshness diperhitungkan oleh backend. EN: Rankings can differ because the backend considers semantic relevance, actual listing price, condition, requested-location match, and freshness.',
            'Intent non-shopping / Non-shopping intent' => 'ID: Untuk permintaan yang tidak berkaitan dengan fungsi Neomatra, sistem mengarahkan pengguna kembali ke pencarian produk, kondisi, budget, lokasi, platform, atau knowledge yang didukung. EN: For requests unrelated to Neomatra, the system guides users back to supported product search, condition, budget, location, platform, or knowledge tasks.',
            'PC component critical attributes' => 'ID: Untuk komponen PC, atribut kritis seperti model GPU, VRAM, socket CPU, chipset motherboard, kapasitas RAM/SSD, dan wattage PSU harus cocok sebelum listing dipetakan ke canonical product. EN: For PC components, critical attributes such as GPU model, VRAM, CPU socket, motherboard chipset, RAM/SSD capacity, and PSU wattage must match before a listing maps to a canonical product.',
            'Laptop critical attributes' => 'ID: Laptop dengan nama family yang sama dapat memiliki CPU, RAM, storage, GPU, atau SKU berbeda. Canonical product harus memakai konfigurasi yang cukup spesifik agar mapping listing tetap aman. EN: Laptops with the same family name can have different CPU, RAM, storage, GPU, or SKU values. Canonical products should be specific enough to keep listing mapping safe.',
            'Smartphone critical attributes' => 'ID: Smartphone harus dibedakan berdasarkan model dan storage, serta RAM jika varian tersebut memengaruhi identitas produk. Varian 128 GB dan 256 GB tidak boleh otomatis dipetakan sebagai produk yang sama. EN: Smartphones should be distinguished by model and storage, and by RAM when it affects product identity. A 128 GB and 256 GB variant must not automatically map as the same product.',
            default => $fallback,
        };
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
