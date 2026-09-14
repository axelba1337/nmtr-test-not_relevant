<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Export\AdminXlsxExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AdminXlsxExportTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_xlsx_exports_require_an_admin(): void
    {
        foreach ($this->exportRoutes() as $route) {
            $this->get(route($route))->assertRedirect(route('admin.login'));
        }

        $user = $this->user(false);

        foreach ($this->exportRoutes() as $route) {
            $this->actingAs($user)->get(route($route))->assertForbidden();
        }
    }

    public function test_category_export_is_real_filtered_xlsx_and_not_limited_to_first_page(): void
    {
        Carbon::setTestNow('2026-09-14 12:34:00');
        $parent = $this->category('Export Root', true, 'export-root');
        $child = $this->category('Export Child', true, 'export-child', $parent);
        $child->update(['description' => 'Deskripsi kategori, aman untuk Excel.']);
        $this->category('Hidden Category', false, 'hidden-category');

        foreach (range(1, 51) as $index) {
            $this->category(
                sprintf('Export Extra %02d', $index),
                true,
                sprintf('export-extra-%02d', $index),
            );
        }

        $response = $this->actingAs($this->user())->get(route(
            'admin.categories.export',
            ['search' => 'Export', 'status' => 'active', 'sort' => 'alphabetical'],
        ));
        $entries = $this->assertXlsx(
            $response,
            'neomatra-categories-2026-09-14-1234.xlsx',
            'Categories',
            'A1:I1',
        );
        $xml = implode("\n", $entries);

        foreach ([
            'ID', 'Category', 'Slug', 'Parent Category', 'Description',
            'Status', 'Sort Order', 'Subcategories Count', 'Products Count',
            'Export Root', 'Export Child', 'Export Extra 51',
        ] as $value) {
            $this->assertStringContainsString($value, $xml);
        }

        $this->assertStringNotContainsString('Hidden Category', $xml);
    }

    public function test_product_export_respects_filters_includes_aliases_and_omits_vectors(): void
    {
        Carbon::setTestNow('2026-09-14 12:35:00');
        config([
            'ai.embedding.model' => 'xlsx-embedding-model',
            'ai.embedding.dimensions' => 2,
        ]);
        $category = $this->category('GPU', true, 'gpu');
        $product = $this->product($category, 'NVIDIA GeForce RTX 3070', true);
        $product->aliases()->create(['alias' => 'RTX3070']);
        $product->aliases()->create(['alias' => 'VGA RTX 3070']);
        $product->forceFill([
            'embedding' => [0.123456789, 0.987654321],
            'embedding_hash' => $product->expectedEmbeddingHash(
                'xlsx-embedding-model',
                2,
            ),
            'embedding_model' => 'xlsx-embedding-model',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
        ])->saveQuietly();
        $this->product($category, 'Inactive RTX Product', false);

        $response = $this->actingAs($this->user())->get(route(
            'admin.products.export',
            [
                'search' => 'RTX 3070',
                'category_id' => $category->id,
                'status' => 'active',
                'listing_status' => 'all',
                'embedding_status' => 'ready',
                'sort' => 'embedding',
            ],
        ));
        $entries = $this->assertXlsx(
            $response,
            'neomatra-products-2026-09-14-1235.xlsx',
            'Products',
            'A1:L1',
        );
        $xml = implode("\n", $entries);

        foreach ([
            'Canonical Name', 'Aliases', 'Active Listings', 'Embedding Status',
            'NVIDIA GeForce RTX 3070', 'RTX3070, VGA RTX 3070', 'READY',
        ] as $value) {
            $this->assertStringContainsString($value, $xml);
        }

        $this->assertStringNotContainsString('Inactive RTX Product', $xml);
        $this->assertStringNotContainsString('0.123456789', $xml);
        $this->assertStringNotContainsString('0.987654321', $xml);
    }

    public function test_platform_export_contains_only_the_five_mvp_sources_and_active_counts(): void
    {
        Carbon::setTestNow('2026-09-14 12:36:00');
        $platforms = collect(Platform::MVP_SLUGS)->mapWithKeys(
            fn (string $slug): array => [$slug => $this->platform($slug)],
        );
        $unsupported = $this->platform('amazon');
        $category = $this->category('GPU', true, 'gpu');
        $product = $this->product($category, 'RTX 3060', true);
        $this->listing(
            $platforms['tokopedia'],
            $product,
            'Tokopedia Counted Listing',
            Listing::CONDITION_NEW,
            'https://www.tokopedia.com/store/rtx-3060',
        );
        $this->listing(
            $unsupported,
            $product,
            'Unsupported Listing',
            Listing::CONDITION_NEW,
            'https://example.com/item/unsupported',
        );

        $entries = $this->assertXlsx(
            $this->actingAs($this->user())->get(route('admin.platforms.export')),
            'neomatra-platforms-2026-09-14-1236.xlsx',
            'Platforms',
            'A1:E1',
        );
        $xml = implode("\n", $entries);

        foreach (['Tokopedia', 'Shopee', 'Olx', 'Facebook', 'Carousell'] as $name) {
            $this->assertStringContainsString($name, $xml);
        }

        $this->assertStringNotContainsString('Amazon', $xml);
        $this->assertSame(5, substr_count($entries['xl/worksheets/sheet1.xml'], '<row r="') - 1);
        $this->assertStringContainsString('<v>1</v>', $entries['xl/worksheets/sheet1.xml']);

        $filteredEntries = $this->assertXlsx(
            $this->actingAs($this->user())->get(route(
                'admin.platforms.export',
                ['search' => 'Shopee', 'status' => 'active'],
            )),
            'neomatra-platforms-2026-09-14-1236.xlsx',
            'Platforms',
            'A1:E1',
        );
        $filteredXml = implode("\n", $filteredEntries);
        $this->assertStringContainsString('Shopee', $filteredXml);
        $this->assertStringNotContainsString('Tokopedia', $filteredXml);
        $this->assertSame(1, substr_count($filteredEntries['xl/worksheets/sheet1.xml'], '<row r="') - 1);
    }

    public function test_listing_export_reuses_platform_condition_and_mapping_filters(): void
    {
        Carbon::setTestNow('2026-09-14 12:37:00');
        $shopee = $this->platform('shopee');
        $olx = $this->platform('olx');
        $category = $this->category('GPU', true, 'gpu');
        $product = $this->product($category, 'NVIDIA GeForce RTX 3070', true);
        $this->listing(
            $shopee,
            $product,
            'Shopee Used Matched',
            Listing::CONDITION_USED,
            'https://shopee.co.id/rtx-3070-used',
            3_500_000,
        );
        $this->listing(
            $shopee,
            $product,
            'Shopee Open Box Matched',
            Listing::CONDITION_OPEN_BOX,
            'https://shopee.co.id/rtx-3070-open-box',
        );
        $this->listing(
            $shopee,
            null,
            'Shopee Used Unmatched',
            Listing::CONDITION_USED,
            'https://shopee.co.id/unmatched',
        );
        $this->listing(
            $olx,
            $product,
            'OLX Used Matched',
            Listing::CONDITION_USED,
            'https://www.olx.co.id/item/rtx-3070',
        );

        $entries = $this->assertXlsx(
            $this->actingAs($this->user())->get(route(
                'admin.listings.export',
                [
                    'platform_id' => $shopee->id,
                    'condition' => 'used',
                    'mapping_status' => 'matched',
                    'status' => 'all',
                ],
            )),
            'neomatra-listings-2026-09-14-1237.xlsx',
            'Listings',
            'A1:O1',
        );
        $xml = implode("\n", $entries);

        foreach ([
            'External Listing ID', 'Mapped Product', 'Product URL',
            'Shopee Used Matched', 'Shopee Open Box Matched',
            'NVIDIA GeForce RTX 3070', 'used', 'open_box',
            'https://shopee.co.id/rtx-3070-used',
        ] as $value) {
            $this->assertStringContainsString($value, $xml);
        }

        $this->assertStringContainsString('<v>3500000</v>', $entries['xl/worksheets/sheet1.xml']);
        $this->assertStringNotContainsString('Shopee Used Unmatched', $xml);
        $this->assertStringNotContainsString('OLX Used Matched', $xml);
    }

    public function test_index_buttons_preserve_normalized_filters_and_localize_the_label(): void
    {
        $admin = $this->user();
        $category = $this->category('GPU', true, 'gpu');
        $platform = $this->platform('shopee');

        $cases = [
            [
                'admin.categories.index',
                ['search' => 'GPU', 'status' => 'active', 'sort' => 'alphabetical'],
                'admin.categories.export',
            ],
            [
                'admin.products.index',
                ['category_id' => $category->id, 'status' => 'active', 'embedding_status' => 'failed'],
                'admin.products.export',
            ],
            [
                'admin.platforms.index',
                ['search' => 'Shopee', 'status' => 'active'],
                'admin.platforms.export',
            ],
            [
                'admin.listings.index',
                ['platform_id' => $platform->id, 'condition' => 'used', 'mapping_status' => 'matched'],
                'admin.listings.export',
            ],
        ];

        foreach ($cases as [$indexRoute, $filters, $exportRoute]) {
            $response = $this->actingAs($admin)
                ->get(route($indexRoute, $filters))
                ->assertOk()
                ->assertSeeText('Unduh XLSX')
                ->assertSee(route($exportRoute), false);

            foreach ($filters as $name => $value) {
                $response->assertSee(
                    rawurlencode((string) $name).'='.rawurlencode((string) $value),
                    false,
                );
            }
        }

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSeeText('Download XLSX');
    }

    /** @return list<string> */
    private function exportRoutes(): array
    {
        return [
            'admin.categories.export',
            'admin.products.export',
            'admin.platforms.export',
            'admin.listings.export',
        ];
    }

    /** @return array<string, string> */
    private function assertXlsx(
        TestResponse $response,
        string $filename,
        string $worksheet,
        string $filterRange,
    ): array {
        $response->assertOk()
            ->assertDownload($filename)
            ->assertHeader('content-type', AdminXlsxExporter::MIME_TYPE);
        $contents = (string) $response->getContent();
        $this->assertStringStartsWith("PK\x03\x04", $contents);
        $this->assertStringNotContainsString('ID,Category,Slug', $contents);

        $entries = $this->xlsxEntries($contents);
        $this->assertArrayHasKey('[Content_Types].xml', $entries);
        $this->assertArrayHasKey('xl/workbook.xml', $entries);
        $this->assertArrayHasKey('xl/worksheets/sheet1.xml', $entries);
        $this->assertStringContainsString('name="'.$worksheet.'"', $entries['xl/workbook.xml']);
        $this->assertStringContainsString('<pane ySplit="1"', $entries['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<autoFilter ref="'.$filterRange.'"', $entries['xl/worksheets/sheet1.xml']);

        return $entries;
    }

    /** @return array<string, string> */
    private function xlsxEntries(string $archive): array
    {
        $entries = [];
        $offset = 0;
        $length = strlen($archive);

        while ($offset + 30 <= $length) {
            $signature = unpack('Vsignature', substr($archive, $offset, 4));

            if (($signature['signature'] ?? null) !== 0x04034B50) {
                break;
            }

            $header = unpack(
                'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vnameLength/vextraLength',
                substr($archive, $offset + 4, 26),
            );
            $nameOffset = $offset + 30;
            $name = substr($archive, $nameOffset, $header['nameLength']);
            $dataOffset = $nameOffset + $header['nameLength'] + $header['extraLength'];
            $compressed = substr($archive, $dataOffset, $header['compressed']);
            $data = match ($header['method']) {
                0 => $compressed,
                8 => gzinflate($compressed),
                default => false,
            };
            $this->assertNotFalse($data, 'Unable to decode XLSX entry '.$name);
            $entries[$name] = $data;
            $offset = $dataOffset + $header['compressed'];
        }

        return $entries;
    }

    private function user(bool $admin = true): User
    {
        return User::query()->create([
            'name' => $admin ? 'Admin' : 'User',
            'email' => uniqid('xlsx-', true).'@example.test',
            'password' => 'password',
            'is_admin' => $admin,
        ]);
    }

    private function category(
        string $name,
        bool $active,
        string $slug,
        ?Category $parent = null,
    ): Category {
        return Category::query()->create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => $slug,
            'description' => null,
            'is_active' => $active,
            'sort_order' => 0,
        ]);
    }

    private function product(Category $category, string $name, bool $active): Product
    {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'brand' => 'NVIDIA',
            'model' => 'RTX 3070',
            'variant' => 'Desktop',
            'sku_or_part_number' => 'RTX-3070',
            'normalized_key' => Product::makeNormalizedKey($name),
            'is_active' => $active,
        ]);
    }

    private function platform(string $slug): Platform
    {
        return Platform::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'status' => Platform::STATUS_READY,
            'is_active' => true,
            'sync_enabled' => false,
        ]);
    }

    private function listing(
        Platform $platform,
        ?Product $product,
        string $title,
        string $condition,
        string $url,
        int $price = 3_000_000,
    ): Listing {
        return Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product?->id,
            'external_listing_id' => hash('crc32b', $url),
            'source_fingerprint' => hash('sha256', $url),
            'title' => $title,
            'price' => $price,
            'currency' => 'IDR',
            'condition' => $condition,
            'seller_name' => 'Marketplace Seller',
            'seller_type' => Listing::SELLER_UNKNOWN,
            'location' => 'Jakarta',
            'product_url' => $url,
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'source_published_at' => now()->subDay(),
            'is_active' => true,
        ]);
    }
}
