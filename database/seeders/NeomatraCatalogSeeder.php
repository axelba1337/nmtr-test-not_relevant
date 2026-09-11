<?php

namespace Database\Seeders;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Neomatra\ListingFingerprintService;
use Illuminate\Database\Seeder;

final class NeomatraCatalogSeeder extends Seeder
{
    public function run(): void
    {
        Category::query()->updateOrCreate(
            ['slug' => 'panduan-neomatra'],
            [
                'parent_id' => null,
                'name' => 'Panduan Neomatra',
                'description' => 'Knowledge umum penggunaan dan batasan Neomatra.',
                'is_active' => true,
                'sort_order' => 5,
            ],
        );

        $taxonomy = (array) config('neomatra.scope.category_taxonomy', []);
        $categories = [];

        foreach ($taxonomy as $slug => $definition) {
            $categories[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'parent_id' => null,
                    'name' => (string) $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'is_active' => true,
                    'sort_order' => (int) $definition['sort_order'],
                ],
            );
        }

        foreach ($taxonomy as $parentSlug => $definition) {
            foreach ((array) ($definition['children'] ?? []) as $slug => $child) {
                $categories[$slug] = Category::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'parent_id' => $categories[$parentSlug]->id,
                        'name' => (string) $child['name'],
                        'description' => null,
                        'is_active' => true,
                        'sort_order' => (int) $child['sort_order'],
                    ],
                );
            }
        }

        $platforms = [];

        foreach ([
            ['Tokopedia', 'tokopedia', Platform::SOURCE_MANUAL, Platform::ACCESS_CSV_IMPORT, 'manual-import', 'https://www.tokopedia.com', false],
            ['Shopee', 'shopee', Platform::SOURCE_MANUAL, Platform::ACCESS_CSV_IMPORT, 'manual-import', 'https://shopee.co.id', false],
            ['OLX', 'olx', Platform::SOURCE_MANUAL, Platform::ACCESS_CSV_IMPORT, 'manual-import', 'https://www.olx.co.id', false],
            ['Facebook Marketplace', 'facebook', Platform::SOURCE_MANUAL, Platform::ACCESS_CSV_IMPORT, 'manual-import', 'https://www.facebook.com/marketplace/', false],
            ['Carousell', 'carousell', Platform::SOURCE_MANUAL, Platform::ACCESS_CSV_IMPORT, 'manual-import', 'https://id.carousell.com', false],
        ] as [$name, $slug, $sourceType, $accessType, $connector, $baseUrl, $syncEnabled]) {
            if (app()->environment('testing') && in_array($slug, ['tokopedia', 'shopee', 'olx'], true)) {
                $sourceType = Platform::SOURCE_DEMO;
                $accessType = Platform::ACCESS_DEMO_DATASET;
                $connector = 'demo-dataset';
                $syncEnabled = true;
            }

            $platforms[$slug] = Platform::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'source_type' => $sourceType,
                    'access_type' => $accessType,
                    'connector' => $connector,
                    'base_url' => $baseUrl,
                    'is_active' => true,
                    'sync_enabled' => $syncEnabled,
                    'status' => Platform::STATUS_READY,
                ],
            );
        }

        $rtx3060 = $this->upsertProduct(
            $categories['gpu'],
            'NVIDIA GeForce RTX 3060',
            'NVIDIA',
            'RTX 3060',
            '12 GB',
            null,
            ['vram_gb' => 12, 'device_type' => 'desktop_gpu'],
            ['RTX 3060 12GB', 'GeForce 3060 12 GB', '3060 non-Ti 12GB'],
        );
        $rtx3060Ti = $this->upsertProduct(
            $categories['gpu'],
            'NVIDIA GeForce RTX 3060 Ti',
            'NVIDIA',
            'RTX 3060 Ti',
            '8 GB',
            null,
            ['vram_gb' => 8, 'device_type' => 'desktop_gpu'],
            ['RTX 3060Ti 8GB', 'GeForce 3060 Ti'],
        );

        if (! app()->environment('testing')) {
            return;
        }

        $fingerprints = app(ListingFingerprintService::class);
        $demoListings = [
            [
                'platform' => $platforms['tokopedia'],
                'product' => $rtx3060,
                'external_id' => 'demo-rtx3060-001',
                'title' => 'RTX 3060 12GB Bekas - Data Demo',
                'price' => 2_500_000,
                'seller' => 'Toko Demo Surabaya',
                'seller_type' => Listing::SELLER_STORE,
                'location' => 'Surabaya',
                'shipping_cost' => 25_000,
                'service_fee' => 20_000,
                'repair_estimate' => 50_000,
                'warranty' => 'Garansi toko 30 hari (data demo)',
                'published_days_ago' => 1,
            ],
            [
                'platform' => $platforms['shopee'],
                'product' => $rtx3060,
                'external_id' => 'demo-rtx3060-002',
                'title' => 'GeForce RTX 3060 12 GB Second - Data Demo',
                'price' => 3_000_000,
                'seller' => 'Seller Demo Jakarta',
                'seller_type' => Listing::SELLER_RESELLER,
                'location' => 'Jakarta',
                'published_days_ago' => 2,
            ],
            [
                'platform' => $platforms['olx'],
                'product' => $rtx3060,
                'external_id' => 'demo-rtx3060-003',
                'title' => 'NVIDIA RTX 3060 12GB Used - Data Demo',
                'price' => 3_100_000,
                'seller' => 'Official Demo Store',
                'seller_type' => Listing::SELLER_OFFICIAL,
                'location' => 'Bandung',
                'published_days_ago' => 3,
            ],
            [
                'platform' => $platforms['tokopedia'],
                'product' => $rtx3060Ti,
                'external_id' => 'demo-rtx3060ti-001',
                'title' => 'RTX 3060 Ti 8GB Bekas - Data Demo',
                'price' => 3_800_000,
                'seller' => 'Toko Demo Bandung',
                'seller_type' => Listing::SELLER_STORE,
                'location' => 'Bandung',
                'published_days_ago' => 2,
            ],
        ];

        foreach ($demoListings as $data) {
            $url = 'https://example.test/neomatra-demo/'
                .$data['external_id'];
            $fingerprint = $fingerprints->make(
                $data['platform']->slug,
                $data['external_id'],
                $url,
                $data['title'],
                $data['price'],
                $data['seller'],
            );

            $listing = Listing::query()->updateOrCreate(
                ['source_fingerprint' => $fingerprint],
                [
                    'platform_id' => $data['platform']->id,
                    'product_id' => $data['product']->id,
                    'external_listing_id' => $data['external_id'],
                    'title' => $data['title'],
                    'description' => 'Listing contoh terstruktur untuk demonstrasi portofolio; bukan penawaran nyata.',
                    'price' => $data['price'],
                    'currency' => 'IDR',
                    'condition' => Listing::CONDITION_USED,
                    'condition_source' => Listing::CONDITION_SOURCE_SELLER,
                    'seller_name' => $data['seller'],
                    'seller_type' => $data['seller_type'],
                    'location' => $data['location'],
                    'product_url' => $url,
                    'image_url' => null,
                    'stock_status' => Listing::STOCK_IN_STOCK,
                    'price_type' => Listing::PRICE_FULL,
                    'shipping_cost' => $data['shipping_cost'] ?? null,
                    'service_fee' => $data['service_fee'] ?? null,
                    'inspection_cost' => null,
                    'repair_estimate' => $data['repair_estimate'] ?? null,
                    'warranty' => $data['warranty'] ?? null,
                    'raw_payload' => [
                        'demo' => true,
                        'connector' => 'seeded-demo',
                    ],
                    'source_published_at' => now()->subDays(
                        $data['published_days_ago'],
                    ),
                    'last_seen_at' => now(),
                    'last_synced_at' => now(),
                    'is_active' => true,
                ],
            );

            if ($listing->first_seen_at === null) {
                $listing->forceFill([
                    'first_seen_at' => $listing->last_seen_at ?? now(),
                ])->save();
            }
        }

    }

    /** @param array<string, scalar|null> $attributes */
    private function upsertProduct(
        Category $category,
        string $name,
        string $brand,
        string $model,
        string $variant,
        ?string $sku,
        array $attributes,
        array $aliases,
    ): Product {
        $normalizedKey = Product::makeNormalizedKey(
            $name,
            $brand,
            $model,
            $variant,
            $sku,
        );
        $product = Product::query()->updateOrCreate(
            ['normalized_key' => $normalizedKey],
            [
                'category_id' => $category->id,
                'name' => $name,
                'brand' => $brand,
                'model' => $model,
                'variant' => $variant,
                'sku_or_part_number' => $sku,
                'attributes' => $attributes,
                'is_active' => true,
            ],
        );

        foreach ($aliases as $alias) {
            ProductAlias::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'normalized_alias' => Normalizer::text($alias),
                ],
                ['alias' => $alias],
            );
        }

        return $product;
    }
}
