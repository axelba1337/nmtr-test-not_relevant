<?php

namespace Database\Seeders;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Neomatra\ListingFingerprintService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

final class NeomatraDatasetV2Seeder extends Seeder
{
    private const DEMO_DESCRIPTION = 'Listing contoh terstruktur untuk demonstrasi portofolio; bukan penawaran marketplace nyata.';

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, Platform> */
    private array $platforms = [];

    public function run(): void
    {
        $this->seedTaxonomy();
        $this->seedPlatforms();
        $products = $this->seedProducts();
        $this->seedDemoListings($products);
        $this->normalizeLegacyDemoProvenance();
    }

    private function normalizeLegacyDemoProvenance(): void
    {
        foreach (Listing::query()->whereNotNull('raw_payload')->lazyById(100) as $listing) {
            $payload = $listing->raw_payload;

            if (! is_array($payload) || ($payload['demo'] ?? false) !== true) {
                continue;
            }

            $values = [];

            if (! filled($payload['connector'] ?? null)) {
                $payload['connector'] = 'seeded-demo';
                $values['raw_payload'] = $payload;
            }

            if ($listing->last_seen_at === null) {
                $values['last_seen_at'] = $listing->last_synced_at
                    ?? $listing->updated_at
                    ?? now();
            }

            if ($listing->first_seen_at === null) {
                $values['first_seen_at'] = $listing->created_at
                    ?? $values['last_seen_at']
                    ?? $listing->last_synced_at
                    ?? now();
            }

            if ($values !== []) {
                $listing->forceFill($values)->save();
            }
        }
    }

    private function seedTaxonomy(): void
    {
        $roots = [
            ['PC Components', 'pc-dan-komponen', 'Komponen utama komputer desktop.', 10],
            ['Handphone', 'handphone', 'Perangkat telepon seluler dan smartphone.', 20],
            ['Laptop', 'laptop', 'Komputer portabel dengan konfigurasi yang teridentifikasi.', 30],
            ['PC Accessories', 'pc-accessories', 'Periferal dan aksesori komputer.', 40],
            ['Networking', 'networking', 'Perangkat jaringan rumah dan bisnis kecil.', 50],
        ];

        foreach ($roots as [$name, $slug, $description, $sortOrder]) {
            $this->categories[$slug] = Category::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'parent_id' => null,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
        }

        $children = [
            'pc-dan-komponen' => [
                ['GPU', 'gpu'],
                ['CPU', 'cpu'],
                ['RAM', 'ram'],
                ['SSD', 'ssd'],
                ['HDD', 'hdd'],
                ['Motherboard', 'motherboard'],
                ['PSU', 'psu'],
                ['CPU Cooler', 'cpu-cooler'],
            ],
            'handphone' => [
                ['Smartphone', 'smartphone'],
            ],
            'laptop' => [
                ['Gaming Laptop', 'gaming-laptop'],
                ['Business Laptop', 'business-laptop'],
                ['Consumer Laptop', 'consumer-laptop'],
            ],
            'pc-accessories' => [
                ['Monitor', 'monitor'],
                ['Keyboard', 'keyboard'],
                ['Mouse', 'mouse'],
                ['Headset', 'headset'],
                ['Webcam', 'webcam'],
                ['Keyboard Mouse Combo', 'keyboard-mouse-combo'],
            ],
            'networking' => [
                ['Router', 'router'],
                ['Access Point', 'access-point'],
                ['Switch', 'switch'],
                ['Modem', 'modem'],
                ['Network Adapter', 'network-adapter'],
            ],
        ];

        foreach ($children as $parentSlug => $items) {
            foreach ($items as $index => [$name, $slug]) {
                $this->categories[$slug] = Category::query()->updateOrCreate(
                    ['slug' => $slug],
                    [
                        'parent_id' => $this->categories[$parentSlug]->id,
                        'name' => $name,
                        'description' => null,
                        'is_active' => true,
                        'sort_order' => ($index + 1) * 10,
                    ],
                );
            }
        }
    }

    private function seedPlatforms(): void
    {
        $records = [
            ['Tokopedia', 'tokopedia', 'https://www.tokopedia.com', true],
            ['Shopee', 'shopee', 'https://shopee.co.id', true],
            ['OLX', 'olx', 'https://www.olx.co.id', true],
            ['Facebook Marketplace', 'facebook', 'https://www.facebook.com/marketplace/', false],
            ['Carousell', 'carousell', 'https://id.carousell.com', false],
            ['Blibli', 'blibli', 'https://www.blibli.com', false],
            ['Lazada', 'lazada', 'https://www.lazada.co.id', false],
            ['Jagofon', 'jagofon', 'https://jagofon.com', false],
            ['Laku6', 'laku6', 'https://www.laku6.com', false],
        ];

        foreach ($records as [$name, $slug, $baseUrl, $isDemo]) {
            $this->platforms[$slug] = Platform::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'source_type' => $isDemo
                        ? Platform::SOURCE_DEMO
                        : Platform::SOURCE_MANUAL,
                    'access_type' => $isDemo
                        ? Platform::ACCESS_DEMO_DATASET
                        : Platform::ACCESS_MANUAL_URL,
                    'connector' => $isDemo ? 'demo-dataset' : null,
                    'base_url' => $baseUrl,
                    'is_active' => true,
                    'sync_enabled' => $isDemo,
                    'status' => Platform::STATUS_READY,
                ],
            );
        }
    }

    /** @return array<string, array{product: Product, reference_price: int}> */
    private function seedProducts(): array
    {
        $checkedAt = CarbonImmutable::parse(
            '2026-08-14 00:00:00',
            'Asia/Jakarta',
        );
        $seeded = [];

        foreach ($this->productDataset() as $data) {
            $normalizedKey = Product::makeNormalizedKey(
                $data['name'],
                $data['brand'],
                $data['model'],
                $data['variant'],
                $data['sku'],
            );
            $product = Product::query()->updateOrCreate(
                ['normalized_key' => $normalizedKey],
                [
                    'category_id' => $this->categories[$data['category']]->id,
                    'name' => $data['name'],
                    'brand' => $data['brand'],
                    'model' => $data['model'],
                    'variant' => $data['variant'],
                    'sku_or_part_number' => $data['sku'],
                    'attributes' => $data['attributes'],
                    'spec_source_name' => $data['source_name'],
                    'spec_source_url' => $data['source_url'],
                    'spec_source_checked_at' => $checkedAt,
                    'is_active' => true,
                ],
            );

            foreach (array_unique($data['aliases']) as $alias) {
                ProductAlias::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'normalized_alias' => Normalizer::text($alias),
                    ],
                    ['alias' => $alias],
                );
            }

            $seeded[$data['key']] = [
                'product' => $product,
                'reference_price' => $data['reference_price'],
            ];
        }

        return $seeded;
    }

    /**
     * @param  array<string, array{product: Product, reference_price: int}>  $products
     */
    private function seedDemoListings(array $products): void
    {
        $fingerprints = app(ListingFingerprintService::class);
        $baseScenarios = [
            ['tokopedia', 'new', Listing::CONDITION_NEW, Listing::SELLER_OFFICIAL, 100, 'Baru', 'Official seller terverifikasi pada dataset demo'],
            ['shopee', 'new', Listing::CONDITION_NEW, Listing::SELLER_STORE, 97, 'Baru', 'Toko Demo Nusantara'],
            ['tokopedia', 'used', Listing::CONDITION_USED, Listing::SELLER_RESELLER, 78, 'Very Good', 'Reseller Demo Teknologi'],
            ['olx', 'used', Listing::CONDITION_USED, Listing::SELLER_INDIVIDUAL, 72, 'Mulus', 'Penjual Individu Demo'],
        ];
        $extraConditions = [
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_EX_DISPLAY,
            Listing::CONDITION_EX_DISPLAY,
            Listing::CONDITION_EX_DISPLAY,
            Listing::CONDITION_UNKNOWN,
            Listing::CONDITION_UNKNOWN,
        ];

        foreach (array_values($products) as $productIndex => $entry) {
            $product = $entry['product'];
            $key = array_keys($products)[$productIndex];

            foreach ($baseScenarios as $scenarioIndex => $scenario) {
                [$platform, $label, $condition, $sellerType, $percent, $sourceCondition, $seller] = $scenario;
                $this->upsertDemoListing(
                    $fingerprints,
                    $product,
                    $key,
                    $platform,
                    $label.'-'.($scenarioIndex + 1),
                    $condition,
                    $sellerType,
                    $seller,
                    $sourceCondition,
                    $this->demoPrice($entry['reference_price'], $percent),
                    ($productIndex * 4) + $scenarioIndex + 1,
                );
            }

            if (isset($extraConditions[$productIndex])) {
                $condition = $extraConditions[$productIndex];
                [$percent, $sourceCondition, $sellerType, $seller] = match ($condition) {
                    Listing::CONDITION_OPEN_BOX => [90, 'Open Box', Listing::SELLER_STORE, 'Toko Open Box Demo'],
                    Listing::CONDITION_REFURBISHED => [82, 'Refurbished', Listing::SELLER_RESELLER, 'Refurbisher Demo'],
                    Listing::CONDITION_EX_DISPLAY => [85, 'Ex Display', Listing::SELLER_STORE, 'Showroom Demo'],
                    default => [70, 'Tidak disebutkan', Listing::SELLER_UNKNOWN, 'Seller Tidak Terverifikasi'],
                };
                $this->upsertDemoListing(
                    $fingerprints,
                    $product,
                    $key,
                    $productIndex % 2 === 0 ? 'shopee' : 'olx',
                    $condition.'-extra',
                    $condition,
                    $sellerType,
                    $seller,
                    $sourceCondition,
                    $this->demoPrice($entry['reference_price'], $percent),
                    140 + $productIndex,
                );
            }
        }
    }

    private function upsertDemoListing(
        ListingFingerprintService $fingerprints,
        Product $product,
        string $productKey,
        string $platformSlug,
        string $scenario,
        string $condition,
        string $sellerType,
        string $seller,
        string $sourceCondition,
        int $price,
        int $freshnessOffset,
    ): void {
        $externalId = "demo-v2-{$productKey}-{$scenario}";
        $url = "https://example.test/neomatra-demo/{$externalId}";
        $title = "{$product->name} {$sourceCondition} - Data Demo";
        $platform = $this->platforms[$platformSlug];
        $fingerprint = $fingerprints->make(
            $platform->slug,
            $externalId,
            $url,
            $title,
            $price,
            $seller,
        );

        $listing = Listing::query()->updateOrCreate(
            ['source_fingerprint' => $fingerprint],
            [
                'platform_id' => $platform->id,
                'product_id' => $product->id,
                'external_listing_id' => $externalId,
                'title' => $title,
                'description' => self::DEMO_DESCRIPTION,
                'price' => $price,
                'currency' => 'IDR',
                'condition' => $condition,
                'condition_source' => Listing::CONDITION_SOURCE_SELLER,
                'seller_name' => $seller,
                'seller_type' => $sellerType,
                'location' => $platformSlug === 'olx' ? 'Bandung' : 'Jakarta',
                'product_url' => $url,
                'image_url' => null,
                'stock_status' => Listing::STOCK_IN_STOCK,
                'price_type' => Listing::PRICE_FULL,
                'shipping_cost' => $platformSlug === 'olx' ? 0 : 20_000,
                'service_fee' => $sellerType === Listing::SELLER_INDIVIDUAL ? 0 : 10_000,
                'inspection_cost' => null,
                'repair_estimate' => null,
                'warranty' => $condition === Listing::CONDITION_NEW
                    ? 'Garansi demo; verifikasi pada platform sumber'
                    : null,
                'raw_payload' => [
                    'demo' => true,
                    'connector' => 'seeded-demo',
                    'source_condition' => $sourceCondition,
                    'official_status_verified' => $sellerType === Listing::SELLER_OFFICIAL,
                ],
                'source_published_at' => now()->subHours($freshnessOffset),
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

    private function demoPrice(int $referencePrice, int $percent): int
    {
        return max(1, intdiv($referencePrice * $percent, 100));
    }

    /** @return list<array<string, mixed>> */
    private function productDataset(): array
    {
        return [
            $this->product('rtx3060', 'gpu', 'NVIDIA GeForce RTX 3060', 'NVIDIA', 'RTX 3060', '12 GB', null, ['device_type' => 'desktop_gpu', 'vram_gb' => 12, 'memory_type' => 'GDDR6'], 'NVIDIA', 'https://www.nvidia.com/id-id/geforce/graphics-cards/30-series/rtx-3060-3060ti/', ['RTX 3060', 'RTX3060', 'RTX 3060 12GB', 'GeForce RTX 3060', 'VGA RTX 3060'], 4_000_000),
            $this->product('rtx3060ti', 'gpu', 'NVIDIA GeForce RTX 3060 Ti', 'NVIDIA', 'RTX 3060 Ti', '8 GB', null, ['device_type' => 'desktop_gpu', 'vram_gb' => 8, 'memory_type' => 'GDDR6'], 'NVIDIA', 'https://www.nvidia.com/id-id/geforce/graphics-cards/30-series/rtx-3060-3060ti/', ['RTX 3060 Ti', 'RTX3060Ti', '3060 Ti', 'RTX 3060Ti 8GB', 'VGA RTX 3060 Ti'], 5_000_000),
            $this->product('rtx4060', 'gpu', 'NVIDIA GeForce RTX 4060', 'NVIDIA', 'RTX 4060', '8 GB GDDR6', null, ['device_type' => 'desktop_gpu', 'vram_gb' => 8, 'memory_type' => 'GDDR6'], 'NVIDIA', 'https://www.nvidia.com/en-us/geforce/graphics-cards/40-series/rtx-4060-4060ti/', ['RTX 4060', 'RTX4060', 'RTX 4060 8GB', 'GeForce RTX 4060', 'VGA RTX 4060'], 5_500_000),
            $this->product('rx6600', 'gpu', 'AMD Radeon RX 6600', 'AMD', 'Radeon RX 6600', '8 GB GDDR6', null, ['device_type' => 'desktop_gpu', 'vram_gb' => 8, 'memory_type' => 'GDDR6'], 'AMD', 'https://www.amd.com/en/products/graphics/desktops/radeon/6000-series/amd-radeon-rx-6600.html', ['RX 6600', 'RX6600', 'Radeon 6600 8GB', 'AMD RX 6600', 'VGA RX 6600'], 3_800_000),
            $this->product('ryzen5600', 'cpu', 'AMD Ryzen 5 5600', 'AMD', 'Ryzen 5 5600', '6 Core 12 Thread', '100-100000927BOX', ['device_type' => 'desktop_cpu', 'cores' => 6, 'threads' => 12, 'socket' => 'AM4'], 'AMD', 'https://www.amd.com/en/products/processors/desktops/ryzen/5000-series/amd-ryzen-5-5600.html', ['Ryzen 5 5600', 'R5 5600', 'Ryzen5600', 'AMD 5600'], 1_800_000),
            $this->product('ryzen7600', 'cpu', 'AMD Ryzen 5 7600', 'AMD', 'Ryzen 5 7600', '6 Core 12 Thread', '100-100001015BOX', ['device_type' => 'desktop_cpu', 'cores' => 6, 'threads' => 12, 'socket' => 'AM5'], 'AMD', 'https://www.amd.com/en/products/processors/desktops/ryzen/7000-series/amd-ryzen-5-7600.html', ['Ryzen 5 7600', 'R5 7600', 'Ryzen7600', 'AMD 7600 AM5'], 3_200_000),
            $this->product('i512400f', 'cpu', 'Intel Core i5-12400F', 'Intel', 'Core i5-12400F', '6 Core 12 Thread', 'BX8071512400F', ['device_type' => 'desktop_cpu', 'cores' => 6, 'threads' => 12, 'socket' => 'LGA1700'], 'Intel ARK', 'https://www.intel.com/content/www/us/en/products/sku/134587/intel-core-i512400f-processor-18m-cache-up-to-4-40-ghz/specifications.html', ['i5-12400F', 'i512400F', 'Intel 12400F', 'Core i5 12400F'], 2_000_000),
            $this->product('i513400f', 'cpu', 'Intel Core i5-13400F', 'Intel', 'Core i5-13400F', '10 Core 16 Thread', 'BX8071513400F', ['device_type' => 'desktop_cpu', 'cores' => 10, 'threads' => 16, 'socket' => 'LGA1700'], 'Intel ARK', 'https://www.intel.com/content/www/us/en/products/sku/230501/intel-core-i513400f-processor-20m-cache-up-to-4-60-ghz/specifications.html', ['i5-13400F', 'i513400F', 'Intel 13400F', 'Core i5 13400F'], 3_100_000),
            $this->product('kingston16', 'ram', 'Kingston FURY Beast DDR4 16GB Kit', 'Kingston', 'FURY Beast', '2x8 GB DDR4-3200', 'KF432C16BBK2/16', ['device_type' => 'desktop_memory', 'capacity_gb' => 16, 'memory_type' => 'DDR4', 'speed_mt_s' => 3200], 'Kingston', 'https://www.kingston.com/en/memory/gaming/kingston-fury-beast-ddr4-memory', ['FURY Beast 16GB 3200', 'KF432C16BBK2/16', 'Kingston Beast 2x8', 'RAM Kingston 16GB DDR4'], 800_000),
            $this->product('corsair16', 'ram', 'Corsair Vengeance LPX DDR4 16GB Kit', 'Corsair', 'Vengeance LPX', '2x8 GB DDR4-3200 C16', 'CMK16GX4M2B3200C16', ['device_type' => 'desktop_memory', 'capacity_gb' => 16, 'memory_type' => 'DDR4', 'speed_mt_s' => 3200], 'Corsair', 'https://www.corsair.com/us/en/p/memory/cmk16gx4m2b3200c16/vengeance-lpx-16gb-2-x-8gb-ddr4-dram-3200mhz-c16-memory-kit-black-cmk16gx4m2b3200c16', ['Vengeance LPX 16GB', 'CMK16GX4M2B3200C16', 'Corsair LPX 2x8', 'RAM Corsair 16GB 3200'], 900_000),
            $this->product('samsung980', 'ssd', 'Samsung 980 NVMe SSD 1TB', 'Samsung', '980', '1 TB', 'MZ-V8V1T0BW', ['device_type' => 'ssd', 'capacity_gb' => 1000, 'interface' => 'PCIe 3.0 x4 NVMe 1.4', 'form_factor' => 'M.2 2280'], 'Samsung', 'https://semiconductor.samsung.com/consumer-storage/internal-ssd/980/', ['Samsung 980 1TB', 'MZ-V8V1T0BW', 'SSD 980 1TB', 'Samsung NVMe 1TB'], 1_200_000),
            $this->product('wdsn580', 'ssd', 'WD Blue SN580 NVMe SSD 1TB', 'Western Digital', 'WD Blue SN580', '1 TB', 'WDS100T3B0E', ['device_type' => 'ssd', 'capacity_gb' => 1000, 'interface' => 'PCIe Gen4 NVMe 1.4b', 'form_factor' => 'M.2 2280'], 'Western Digital', 'https://documents.westerndigital.com/content/dam/doc-library/en_us/assets/public/western-digital/product/internal-drives/wd-blue-ssd/product-brief-wd-blue-sn580-nvme-ssd.pdf', ['WD SN580 1TB', 'WDS100T3B0E', 'Blue SN580', 'SSD WD Blue 1TB'], 1_150_000),
            $this->product('asus-b550', 'motherboard', 'ASUS TUF GAMING B550-PLUS', 'ASUS', 'TUF GAMING B550-PLUS', 'ATX AM4', null, ['device_type' => 'motherboard', 'socket' => 'AM4', 'chipset' => 'B550', 'form_factor' => 'ATX'], 'ASUS', 'https://www.asus.com/id/motherboards-components/motherboards/tuf-gaming/tuf-gaming-b550-plus/techspec/', ['TUF B550 PLUS', 'ASUS B550-PLUS', 'TUF GAMING B550 PLUS', 'Motherboard TUF B550'], 2_600_000),
            $this->product('msi-b650m', 'motherboard', 'MSI B650M GAMING PLUS WIFI', 'MSI', 'B650M GAMING PLUS WIFI', 'Micro-ATX AM5', null, ['device_type' => 'motherboard', 'socket' => 'AM5', 'chipset' => 'B650', 'form_factor' => 'Micro-ATX'], 'MSI', 'https://www.msi.com/Motherboard/B650M-GAMING-PLUS-WIFI/Specification', ['MSI B650M Gaming Plus', 'B650M GAMING PLUS WIFI', 'MSI B650M WiFi', 'Motherboard B650M MSI'], 3_000_000),
            $this->product('corsair-cx650', 'psu', 'Corsair CX650 650W', 'Corsair', 'CX650', '650W 80 PLUS Bronze', 'CP-9020278-NA', ['device_type' => 'power_supply', 'wattage_w' => 650, 'efficiency_rating' => '80 PLUS Bronze'], 'Corsair', 'https://www.corsair.com/us/en/p/psu/cp-9020278-na/cx-series-cx650-650-watt-80-plus-bronze-atx-power-supply-cp-9020278-na', ['Corsair CX650', 'CX650 650W', 'CP-9020278-NA', 'PSU Corsair 650W'], 1_050_000),
            $this->product('cm-mwe650', 'psu', 'Cooler Master MWE Bronze 650 V2', 'Cooler Master', 'MWE Bronze 650 V2', '650W 80 PLUS Bronze', 'MPE-6501-ACAAB', ['device_type' => 'power_supply', 'wattage_w' => 650, 'efficiency_rating' => '80 PLUS Bronze'], 'Cooler Master', 'https://www.coolermaster.com/en-global/products/mwe-650-bronze-v2/', ['MWE 650 Bronze V2', 'MPE-6501-ACAAB', 'Cooler Master 650W V2', 'PSU MWE 650'], 1_000_000),
            $this->product('deepcool-ak400', 'cpu-cooler', 'DeepCool AK400', 'DeepCool', 'AK400', 'Single Tower', 'R-AK400-BKNNMN-G-1', ['device_type' => 'cpu_cooler', 'cooler_type' => 'air', 'fan_size_mm' => 120], 'DeepCool', 'https://www.deepcool.com/products/Cooling/cpuaircoolers/AK400-Performance-CPU-Cooler/2021/15222.shtml', ['DeepCool AK400', 'AK400 Cooler', 'R-AK400-BKNNMN-G-1', 'CPU Cooler AK400'], 600_000),
            $this->product('iphone13-128', 'smartphone', 'Apple iPhone 13 128GB', 'Apple', 'iPhone 13', '128 GB', null, ['device_type' => 'smartphone', 'storage_gb' => 128, 'display_inches' => 6.1], 'Apple Technical Specifications', 'https://support.apple.com/id-id/111872', ['iPhone 13 128GB', 'iPhone13 128', 'Apple 13 128', 'HP iPhone 13'], 10_000_000),
            $this->product('iphone15-128', 'smartphone', 'Apple iPhone 15 128GB', 'Apple', 'iPhone 15', '128 GB', null, ['device_type' => 'smartphone', 'storage_gb' => 128, 'display_inches' => 6.1], 'Apple', 'https://www.apple.com/id/newsroom/2023/09/apple-debuts-iphone-15-and-iphone-15-plus/', ['iPhone 15 128GB', 'iPhone15 128', 'Apple 15 128', 'HP iPhone 15'], 14_000_000),
            $this->product('galaxy-s23', 'smartphone', 'Samsung Galaxy S23 8GB/256GB', 'Samsung', 'Galaxy S23', '8 GB RAM / 256 GB', 'SM-S911BZKCXID', ['device_type' => 'smartphone', 'storage_gb' => 256, 'ram_gb' => 8, 'display_inches' => 6.1], 'Samsung Indonesia', 'https://www.samsung.com/id/smartphones/galaxy-s/galaxy-s23-phantom-black-256gb-sm-s911bzkcxid/', ['Galaxy S23 256GB', 'S23 8/256', 'SM-S911BZKCXID', 'Samsung S23'], 11_000_000),
            $this->product('galaxy-s24fe', 'smartphone', 'Samsung Galaxy S24 FE 8GB/256GB', 'Samsung', 'Galaxy S24 FE', '8 GB RAM / 256 GB', 'SM-S721BLBCXID', ['device_type' => 'smartphone', 'storage_gb' => 256, 'ram_gb' => 8, 'display_inches' => 6.7], 'Samsung Indonesia', 'https://www.samsung.com/id/smartphones/galaxy-s/galaxy-s24-fe-blue-256gb-sm-s721blbcxid/', ['Galaxy S24 FE 256GB', 'S24FE 8/256', 'SM-S721BLBCXID', 'Samsung S24 FE'], 11_000_000),
            $this->product('asus-a1405va', 'consumer-laptop', 'ASUS Vivobook 14 A1405VA', 'ASUS', 'A1405VA', 'Core i5-13420H / 16 GB / 512 GB', null, ['device_type' => 'laptop', 'cpu' => 'Intel Core i5-13420H', 'ram_gb' => 16, 'storage_gb' => 512, 'gpu' => 'Intel UHD Graphics', 'display_inches' => 14], 'ASUS Indonesia', 'https://www.asus.com/id/laptops/for-home/vivobook/asus-vivobook-14-oled-a1405/techspec/', ['Vivobook 14 A1405VA', 'ASUS A1405VA i5', 'Vivobook i5 16/512', 'Laptop ASUS A1405'], 12_000_000),
            $this->product('lenovo-k14g3', 'business-laptop', 'Lenovo K14 Gen 3 21KL0019ID', 'Lenovo', 'K14 Gen 3', 'Core Ultra 7 155U / 16 GB / 512 GB', '21KL0019ID', ['device_type' => 'laptop', 'cpu' => 'Intel Core Ultra 7 155U', 'ram_gb' => 16, 'storage_gb' => 512, 'display_inches' => 14], 'Lenovo Indonesia e-Catalogue', 'https://pages.lenovo.com/rs/183-WCT-620/images/Lenovo_e-Catalogue_LenovoK14Gen3.pdf', ['Lenovo K14 Gen 3', '21KL0019ID', 'K14 Ultra 7 16/512', 'Laptop Lenovo K14'], 17_000_000),
            $this->product('hp-probook445', 'business-laptop', 'HP ProBook 445 G10', 'HP', 'ProBook 445 G10', 'Ryzen 5 7530U / 16 GB / 512 GB', '8L0J2UA', ['device_type' => 'laptop', 'cpu' => 'AMD Ryzen 5 7530U', 'ram_gb' => 16, 'storage_gb' => 512, 'display_inches' => 14], 'HP Support', 'https://support.hp.com/id-en/product/details/hp-probook-445-14-inch-g10-notebook-pc/model/2101515954?sku=8L0J2UA', ['HP ProBook 445 G10', '8L0J2UA', 'ProBook 445 Ryzen 5', 'Laptop HP 445 G10'], 14_000_000),
            $this->product('acer-ag14', 'consumer-laptop', 'Acer Aspire Go 14 AG14-71M-5471', 'Acer', 'AG14-71M-5471', '16 GB DDR5 / 512 GB NVMe', 'NX.JFWSN.001', ['device_type' => 'laptop', 'ram_gb' => 16, 'storage_gb' => 512, 'display_inches' => 14], 'Acer Indonesia', 'https://www.acer.com/id-id/laptops/aspire/aspire-go-intel/pdp/NX.JFWSN.001', ['Acer Aspire Go 14', 'AG14-71M-5471', 'NX.JFWSN.001', 'Aspire Go 16/512'], 9_000_000),
            $this->product('logitech-g102', 'mouse', 'Logitech G102 LIGHTSYNC', 'Logitech', 'G102 LIGHTSYNC', 'Black', '910-005802', ['device_type' => 'mouse', 'connection' => 'USB wired', 'sensor_dpi_max' => 8000], 'Logitech Indonesia', 'https://www.logitech.com/id-id/shop/p/g102-lightsync-gaming-mouse.910-005802', ['Logitech G102', 'G102 LIGHTSYNC', '910-005802', 'Mouse Gaming G102'], 350_000),
            $this->product('logitech-k380', 'keyboard', 'Logitech K380 Multi-Device Bluetooth Keyboard', 'Logitech', 'K380', 'Multi-Device Bluetooth', '920-007596', ['device_type' => 'keyboard', 'connection' => 'Bluetooth', 'layout_size' => 'compact'], 'Logitech Indonesia', 'https://www.logitech.com/id-id/products/keyboards/k380-multi-device.920-007596.html', ['Logitech K380', 'K380 Keyboard', '920-007596', 'Keyboard Bluetooth K380'], 550_000),
            $this->product('logitech-c920', 'webcam', 'Logitech C920 HD Pro Webcam', 'Logitech', 'C920 HD Pro', '1080p', '960-001055', ['device_type' => 'webcam', 'resolution' => '1080p'], 'Logitech Indonesia', 'https://www.logitech.com/id-id/products/webcams/c920-pro-hd-webcam.960-001055.html', ['Logitech C920', 'C920 HD Pro', '960-001055', 'Webcam C920 1080p'], 1_300_000),
            $this->product('archer-ax23', 'router', 'TP-Link Archer AX23', 'TP-Link', 'Archer AX23', 'AX1800 Wi-Fi 6', null, ['device_type' => 'router', 'wifi_generation' => 'Wi-Fi 6', 'wifi_class' => 'AX1800'], 'TP-Link Indonesia', 'https://www.tp-link.com/id/home-networking/wifi-router/archer-ax23/', ['Archer AX23', 'TP Link AX23', 'TP-Link AX1800 AX23', 'Router AX23'], 900_000),
            $this->product('archer-ax53', 'router', 'TP-Link Archer AX53', 'TP-Link', 'Archer AX53', 'AX3000 Wi-Fi 6', null, ['device_type' => 'router', 'wifi_generation' => 'Wi-Fi 6', 'wifi_class' => 'AX3000'], 'TP-Link Indonesia', 'https://www.tp-link.com/id/home-networking/wifi-router/archer-ax53/v2/', ['Archer AX53', 'TP Link AX53', 'TP-Link AX3000 AX53', 'Router AX53'], 1_300_000),
            $this->product('eap225', 'access-point', 'TP-Link EAP225', 'TP-Link', 'EAP225', 'AC1350 Ceiling Mount', null, ['device_type' => 'access_point', 'wifi_generation' => 'Wi-Fi 5', 'wifi_class' => 'AC1350'], 'TP-Link Indonesia', 'https://www.tp-link.com/id/business-networking/omada-sdn-access-point/eap225/v3.20/', ['TP-Link EAP225', 'EAP225 AC1350', 'Omada EAP225', 'Access Point EAP225'], 1_300_000),
            $this->product('tl-sg108', 'switch', 'TP-Link TL-SG108', 'TP-Link', 'TL-SG108', '8-Port Gigabit', null, ['device_type' => 'network_switch', 'ports' => 8, 'ethernet_speed_mbps' => 1000], 'TP-Link Indonesia', 'https://www.tp-link.com/id/business-networking/soho-switch-unmanaged/tl-sg108/', ['TP-Link TL-SG108', 'TL SG108', 'Switch 8 Port Gigabit', 'SG108 TP Link'], 450_000),
        ];
    }

    /** @return array<string, mixed> */
    private function product(
        string $key,
        string $category,
        string $name,
        string $brand,
        string $model,
        string $variant,
        ?string $sku,
        array $attributes,
        string $sourceName,
        string $sourceUrl,
        array $aliases,
        int $referencePrice,
    ): array {
        return compact(
            'key',
            'category',
            'name',
            'brand',
            'model',
            'variant',
            'sku',
            'attributes',
            'sourceName',
            'sourceUrl',
            'aliases',
            'referencePrice',
        ) + [
            'source_name' => $sourceName,
            'source_url' => $sourceUrl,
            'reference_price' => $referencePrice,
        ];
    }
}
