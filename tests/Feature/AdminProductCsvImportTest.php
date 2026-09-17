<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminProductCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $gpu;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->admin = User::query()->create([
            'name' => 'Product Import Admin',
            'email' => 'product-import@example.test',
            'password' => 'test-password',
            'is_admin' => true,
        ]);
        $root = Category::query()->create([
            'name' => 'PC Components',
            'slug' => 'pc-dan-komponen',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $this->gpu = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    public function test_product_csv_routes_require_admin_and_action_is_visible(): void
    {
        $routes = [
            route('admin.products.import'),
            route('admin.products.import.template'),
        ];

        foreach ($routes as $route) {
            $this->get($route)->assertRedirect(route('admin.login'));
        }

        $this->post(route('admin.products.import.preview'))
            ->assertRedirect(route('admin.login'));
        $this->post(route('admin.products.import.confirm'))
            ->assertRedirect(route('admin.login'));

        $nonAdmin = User::query()->create([
            'name' => 'Regular User',
            'email' => 'product-import-user@example.test',
            'password' => 'test-password',
            'is_admin' => false,
        ]);
        $this->actingAs($nonAdmin)
            ->get(route('admin.products.import'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSeeText('IMPOR PRODUCT CSV')
            ->assertSee('href="'.route('admin.products.import').'"', false);
    }

    public function test_template_download_has_the_exact_safe_header(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.products.import.template'))
            ->assertOk()
            ->assertDownload('neomatra-product-template.csv');

        $header = str_getcsv(
            trim($response->streamedContent()),
            ',',
            '"',
            '',
        );

        $this->assertSame(ProductCsvImportService::HEADERS, $header);
        $this->assertNotContains('id', $header);
        $this->assertNotContains('embedding', $header);
        $this->assertNotContains('listing_count', $header);
    }

    public function test_header_only_csv_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.products.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'products.csv',
                    $this->csv([]),
                ),
            ]);

        $response->assertSessionHasErrors([
            'csv' => 'CSV harus berisi minimal satu baris data Product selain header.',
        ]);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_aliases', 0);
        Http::assertNothingSent();
    }

    public function test_preview_classifies_rows_without_database_mutation(): void
    {
        $existing = Product::query()->create([
            'category_id' => $this->gpu->id,
            'name' => 'Existing Canonical GPU',
            'brand' => 'Existing',
            'model' => 'GPU 1',
            'sku_or_part_number' => 'EXIST-001',
            'is_active' => true,
        ]);
        $existing->aliases()->create(['alias' => 'Existing VGA Alias']);
        Category::query()->create([
            'name' => 'Vehicles',
            'slug' => 'vehicles',
            'is_active' => true,
            'sort_order' => 99,
        ]);
        $before = [
            'products' => Product::query()->count(),
            'aliases' => $existing->aliases()->count(),
            'categories' => Category::query()->count(),
        ];
        $ready = $this->row([
            'canonical_name' => 'CSV Canonical GPU',
            'brand' => 'Neomatra',
            'model' => 'CSV-GPU-1',
            'sku_or_part_number' => 'CSV-001',
            'aliases' => 'CSV VGA|Graphic Card CSV',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('admin.products.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'products.csv',
                    $this->csv([
                        $ready,
                        $ready,
                        $this->row([
                            'canonical_name' => 'Existing Canonical GPU',
                            'brand' => 'Existing',
                            'model' => 'GPU 1',
                            'sku_or_part_number' => 'EXIST-001',
                        ]),
                        $this->row([
                            'category' => 'Vehicles',
                            'canonical_name' => 'Out of Scope Product',
                        ]),
                        $this->row([
                            'canonical_name' => 'SKU Collision Product',
                            'sku_or_part_number' => 'exist-001',
                        ]),
                        $this->row([
                            'canonical_name' => 'Noisy Alias Product',
                            'aliases' => 'GPU promo murah',
                        ]),
                        $this->row([
                            'canonical_name' => 'Alias Conflict Product',
                            'aliases' => 'Existing VGA Alias',
                        ]),
                        $this->row([
                            'canonical_name' => 'Duplicate Alias Product',
                            'aliases' => 'VGA Same|vga same',
                        ]),
                    ]),
                ),
            ])
            ->assertOk()
            ->assertSeeText('SIAP DIBUAT')
            ->assertSeeText('PRODUCT TERSEDIA')
            ->assertSeeText('DUPLIKAT DALAM CSV')
            ->assertSeeText('Kategori tidak dikenal atau berada di luar scope Product Neomatra.')
            ->assertSeeText('SKU sudah digunakan oleh Product lain.')
            ->assertSeeText('Alias mengandung kata promosi/noisy atau format yang tidak aman.')
            ->assertSeeText('Alias sudah dimiliki oleh Product lain.')
            ->assertSeeText('Alias yang sama muncul lebih dari sekali pada baris ini.');

        $summary = $response->viewData('preview')['summary'];
        $this->assertSame(8, $summary['rows']);
        $this->assertSame(1, $summary['ready']);
        $this->assertSame(1, $summary['existing']);
        $this->assertSame(1, $summary['duplicate']);
        $this->assertSame(5, $summary['invalid']);
        $this->assertDatabaseCount('products', $before['products']);
        $this->assertDatabaseCount('product_aliases', $before['aliases']);
        $this->assertDatabaseCount('categories', $before['categories']);
        $this->assertDatabaseCount('listings', 0);
        Http::assertNothingSent();
    }

    public function test_confirm_revalidates_and_creates_only_approved_valid_products(): void
    {
        Product::query()->create([
            'category_id' => $this->gpu->id,
            'name' => 'Existing CSV Product',
            'brand' => 'Existing',
            'model' => 'EX-1',
            'is_active' => true,
        ]);
        $categoryCount = Category::query()->count();
        $response = $this->actingAs($this->admin)
            ->post(route('admin.products.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'products.csv',
                    $this->csv([
                        $this->row([
                            'canonical_name' => 'Imported Safe GPU',
                            'brand' => 'Neomatra',
                            'model' => 'SAFE-1',
                            'variant' => '12 GB',
                            'sku_or_part_number' => 'SAFE-SKU-1',
                            'aliases' => 'Safe VGA|Safe Graphics Card',
                            'specification_source' => 'https://example.test/specifications/safe-gpu',
                        ]),
                        $this->row([
                            'canonical_name' => 'Existing CSV Product',
                            'brand' => 'Existing',
                            'model' => 'EX-1',
                        ]),
                        $this->row([
                            'category' => 'Automotive',
                            'canonical_name' => 'Invalid Product',
                        ]),
                    ]),
                ),
            ])
            ->assertOk();
        $token = $response->viewData('previewToken');

        $this->actingAs($this->admin)
            ->post(route('admin.products.import.confirm'), [
                'preview_token' => $token,
                'approved_rows' => [2],
            ])
            ->assertRedirect(route('admin.products.import'))
            ->assertSessionHas('success')
            ->assertSessionHas('product_import_result', function (array $result): bool {
                return $result === [
                    'created' => 1,
                    'skipped_existing' => 1,
                    'skipped_duplicate' => 0,
                    'invalid' => 1,
                    'failed' => 0,
                ];
            });

        $product = Product::query()
            ->where('name', 'Imported Safe GPU')
            ->sole();
        $this->assertSame('SAFE-SKU-1', $product->sku_or_part_number);
        $this->assertSame('example.test', $product->spec_source_name);
        $this->assertSame(
            'https://example.test/specifications/safe-gpu',
            $product->spec_source_url,
        );
        $this->assertTrue($product->is_active);
        $this->assertNull($product->embedding);
        $this->assertNull($product->embedding_hash);
        $this->assertSame(
            Product::EMBEDDING_NEEDS_REBUILD,
            $product->embeddingStatus(
                (string) config('ai.embedding.model'),
                (int) config('ai.embedding.dimensions'),
            ),
        );
        $this->assertSame(
            ['safe graphics card', 'safe vga'],
            $product->aliases()->orderBy('normalized_alias')
                ->pluck('normalized_alias')->all(),
        );
        $this->assertSame($categoryCount, Category::query()->count());
        $this->assertDatabaseMissing('products', ['name' => 'Invalid Product']);
        $this->assertDatabaseCount('listings', 0);
        Http::assertNothingSent();
    }

    public function test_preview_token_is_single_use_and_import_never_updates_existing_product(): void
    {
        $existing = Product::query()->create([
            'category_id' => $this->gpu->id,
            'name' => 'Do Not Update Product',
            'brand' => 'Stable',
            'model' => 'KEEP-1',
            'is_active' => true,
        ]);
        $response = $this->actingAs($this->admin)
            ->post(route('admin.products.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent(
                    'products.csv',
                    $this->csv([
                        $this->row([
                            'canonical_name' => 'Brand New Approved Product',
                            'model' => 'NEW-1',
                        ]),
                        $this->row([
                            'canonical_name' => 'Do Not Update Product',
                            'brand' => 'Stable',
                            'model' => 'KEEP-1',
                            'aliases' => 'Must Not Be Added',
                            'is_active' => 'false',
                        ]),
                    ]),
                ),
            ])
            ->assertOk();
        $token = $response->viewData('previewToken');

        $payload = [
            'preview_token' => $token,
            'approved_rows' => [2],
        ];
        $this->actingAs($this->admin)
            ->post(route('admin.products.import.confirm'), $payload)
            ->assertRedirect(route('admin.products.import'));
        $this->actingAs($this->admin)
            ->post(route('admin.products.import.confirm'), $payload)
            ->assertSessionHasErrors('preview_token');

        $existing->refresh();
        $this->assertTrue($existing->is_active);
        $this->assertSame(0, $existing->aliases()->count());
        $this->assertDatabaseCount('products', 2);
        Http::assertNothingSent();
    }

    /** @param list<array<string, string>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fputcsv($stream, ProductCsvImportService::HEADERS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                static fn (string $header): string => $row[$header] ?? '',
                ProductCsvImportService::HEADERS,
            ), ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $this->assertIsString($contents);

        return $contents;
    }

    /** @param array<string, string> $overrides */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'category' => 'GPU',
            'canonical_name' => 'Test Canonical GPU',
            'brand' => 'Test Brand',
            'model' => 'TEST-1',
            'variant' => '',
            'sku_or_part_number' => '',
            'aliases' => '',
            'specification_source' => '',
            'is_active' => 'true',
        ], $overrides);
    }
}
