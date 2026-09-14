<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\MarketplaceCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminListingOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->platforms();
    }

    public function test_listing_operation_routes_require_an_admin(): void
    {
        foreach ([
            route('admin.listings.import'),
            route('admin.listings.import.template'),
            route('admin.listings.coverage'),
            route('admin.listings.coverage.export'),
            route('admin.listings.coverage.research-prompt'),
            route('admin.listings.discovery'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }

        foreach ([
            route('admin.listings.import.preview'),
            route('admin.listings.import.confirm'),
            route('admin.listings.discovery.start'),
        ] as $url) {
            $this->post($url)->assertRedirect(route('admin.login'));
        }

        $user = $this->user(false);

        $this->actingAs($user)->get(route('admin.listings.import'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.listings.coverage'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.listings.discovery'))->assertForbidden();
    }

    public function test_template_has_the_exact_existing_import_header(): void
    {
        $response = $this->actingAs($this->user())
            ->get(route('admin.listings.import.template'))
            ->assertOk()
            ->assertDownload('neomatra-marketplace-listings-template.csv');

        $this->assertSame(
            implode(',', MarketplaceCsvImportService::HEADERS)."\n",
            str_replace("\r\n", "\n", $response->streamedContent()),
        );
    }

    public function test_import_page_presents_the_compact_manual_five_step_workflow(): void
    {
        config(['listing-discovery.target_active_listings' => 5]);
        $this->product('NVIDIA GeForce RTX 3060', 'RTX 3060');

        $response = $this->actingAs($this->user())
            ->get(route('admin.listings.import'))
            ->assertOk();

        foreach ([
            'Produk yang Memerlukan Listing',
            'Cari Listing Marketplace Nyata',
            'Siapkan CSV Listing',
            'Unggah dan Validasi',
        ] as $heading) {
            $response->assertSeeText($heading);
        }

        $response->assertSee('data-copy-target="#listing-research-prompt"', false)
            ->assertSeeText('NVIDIA GeForce RTX 3060')
            ->assertSeeText('Unduh Daftar Target Produk')
            ->assertSeeText('Prompt riset menggunakan maksimal 10 produk prioritas')
            ->assertSeeText('Gunakan prompt ini pada AI yang memiliki kemampuan pencarian web')
            ->assertSeeText('Unduh Prompt .MD')
            ->assertSeeText('Pencarian dapat dilakukan manual atau dengan prompt riset AI dari Langkah 1.')
            ->assertDontSeeText('Workflow CSV bersifat manual:')
            ->assertDontSee('ui.admin_pages.', false);

        $this->actingAs($this->user())
            ->withSession(['locale' => 'en'])
            ->get(route('admin.listings.import'))
            ->assertOk()
            ->assertSeeText('The research prompt uses at most 10 priority products')
            ->assertSeeText('Use this prompt with an AI that has web browsing capabilities')
            ->assertSeeText('Download Prompt .MD')
            ->assertSeeText('You can search manually or use the AI research prompt from Step 1.')
            ->assertDontSee('ui.admin_pages.', false);
    }

    public function test_valid_preview_does_not_mutate_catalog_then_confirm_imports_once(): void
    {
        $admin = $this->user();
        $product = $this->product('NVIDIA GeForce RTX 3060', 'RTX 3060');
        $product->aliases()->create(['alias' => 'VGA RTX 3060']);
        $counts = [
            'products' => Product::query()->count(),
            'aliases' => $product->aliases()->count(),
            'listings' => Listing::query()->count(),
        ];

        $preview = $this->actingAs($admin)->post(
            route('admin.listings.import.preview'),
            ['csv' => $this->upload([$this->validRow()])],
        )->assertOk();
        $summary = $preview->viewData('preview')['summary'];

        $this->assertSame([
            'rows' => 1,
            'valid' => 1,
            'rejected' => 0,
            'duplicate' => 0,
            'matched' => 1,
            'unmatched' => 0,
        ], $summary);
        $this->assertSame($counts['products'], Product::query()->count());
        $this->assertSame($counts['aliases'], $product->aliases()->count());
        $this->assertSame($counts['listings'], Listing::query()->count());

        $token = $preview->viewData('previewToken');
        $this->actingAs($admin)
            ->post(route('admin.listings.import.confirm'), [
                'preview_token' => $token,
            ])
            ->assertRedirect(route('admin.listings.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'WEB-RTX3060-001',
            'product_id' => $product->id,
        ]);
        $this->assertSame($counts['products'], Product::query()->count());
        $this->assertSame($counts['aliases'], $product->aliases()->count());

        $this->actingAs($admin)
            ->post(route('admin.listings.import.confirm'), [
                'preview_token' => $token,
            ])
            ->assertSessionHasErrors('preview_token');
        $this->assertDatabaseCount('listings', $counts['listings'] + 1);
    }

    public function test_preview_reports_invalid_platform_domain_price_and_duplicate_url(): void
    {
        $this->product('NVIDIA GeForce RTX 3060', 'RTX 3060');
        $valid = $this->validRow();
        $duplicate = $valid;
        $duplicate[1] = 'WEB-RTX3060-DUPLICATE';
        $duplicate[10] .= '?tracking=duplicate';
        $invalidPlatform = $valid;
        $invalidPlatform[0] = 'amazon';
        $invalidPlatform[1] = 'INVALID-PLATFORM';
        $wrongDomain = $valid;
        $wrongDomain[1] = 'WRONG-DOMAIN';
        $wrongDomain[10] = 'https://example.test/item/1';
        $invalidPrice = $valid;
        $invalidPrice[1] = 'INVALID-PRICE';
        $invalidPrice[4] = 'Rp 3.100.000';

        $response = $this->actingAs($this->user())->post(
            route('admin.listings.import.preview'),
            ['csv' => $this->upload([
                $valid,
                $duplicate,
                $invalidPlatform,
                $wrongDomain,
                $invalidPrice,
            ])],
        )->assertOk();
        $summary = $response->viewData('preview')['summary'];
        $details = $response->viewData('preview')['details'];

        $this->assertSame(5, $summary['rows']);
        $this->assertSame(1, $summary['valid']);
        $this->assertSame(3, $summary['rejected']);
        $this->assertSame(1, $summary['duplicate']);
        $this->assertCount(5, $details);
        $this->assertSame('valid', $details[0]['status']);
        $this->assertSame('duplicate URL', $details[1]['reason']);
        $this->assertSame('invalid', $details[2]['status']);
        $this->assertSame('platform tidak didukung', $details[2]['reason']);
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_confirm_skips_invalid_rows_and_keeps_uncertain_listing_unmatched_without_catalog_mutation(): void
    {
        $admin = $this->user();
        $this->product('NVIDIA GeForce RTX 3060', 'RTX 3060');
        $productCount = Product::query()->count();
        $aliasCount = Product::query()->withCount('aliases')->get()->sum('aliases_count');
        $categoryCount = Category::query()->count();
        $unmatched = $this->validRow();
        $unmatched[1] = 'UNMATCHED-001';
        $unmatched[2] = 'Perangkat Misterius Model Tidak Dikenal';
        $unmatched[3] = 'Tidak memiliki identitas produk kanonik.';
        $unmatched[10] = 'https://www.olx.co.id/item/perangkat-misterius-iid-991102';
        $invalid = $this->validRow();
        $invalid[1] = 'INVALID-001';
        $invalid[4] = 'Rp 100.000';

        $response = $this->actingAs($admin)->post(
            route('admin.listings.import.preview'),
            ['csv' => $this->upload([$unmatched, $invalid])],
        )->assertOk();
        $preview = $response->viewData('preview');

        $this->assertSame(2, $preview['summary']['rows']);
        $this->assertSame(1, $preview['summary']['valid']);
        $this->assertSame(1, $preview['summary']['rejected']);
        $this->assertSame(1, $preview['summary']['unmatched']);
        $response->assertSeeText('Unmatched (product_id kosong)')
            ->assertSeeText('invalid price')
            ->assertSeeText('IMPOR 1 BARIS VALID');

        $this->actingAs($admin)->post(route('admin.listings.import.confirm'), [
            'preview_token' => $response->viewData('previewToken'),
        ])->assertRedirect(route('admin.listings.index'));

        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'UNMATCHED-001',
            'product_id' => null,
        ]);
        $this->assertDatabaseMissing('listings', ['external_listing_id' => 'INVALID-001']);
        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame($aliasCount, Product::query()->withCount('aliases')->get()->sum('aliases_count'));
        $this->assertSame($categoryCount, Category::query()->count());
    }

    public function test_invalid_header_is_rejected_without_mutation(): void
    {
        $content = "platform,title,price,product_url\nolx,Test,1000,https://www.olx.co.id/item/test\n";

        $this->actingAs($this->user())
            ->from(route('admin.listings.import'))
            ->post(route('admin.listings.import.preview'), [
                'csv' => UploadedFile::fake()->createWithContent('invalid.csv', $content),
            ])
            ->assertRedirect(route('admin.listings.import'))
            ->assertSessionHasErrors('csv');

        $this->assertDatabaseCount('listings', 0);
    }

    public function test_admin_listing_filter_uses_public_condition_groups(): void
    {
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        $product = $this->product('NVIDIA GeForce RTX 3070', 'RTX 3070');

        foreach (Listing::conditions() as $condition) {
            $this->listing($platform, $product, $condition);
        }

        $response = $this->actingAs($this->user())
            ->get(route('admin.listings.index', ['condition' => 'used']))
            ->assertOk();
        $shown = $response->viewData('listings')->getCollection();

        $this->assertEqualsCanonicalizing([
            Listing::CONDITION_USED,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_EX_DISPLAY,
        ], $shown->pluck('condition')->all());
        $response->assertSee('value="new"', false)
            ->assertSee('value="used"', false)
            ->assertSee('value="unknown"', false)
            ->assertSeeText('Semua kondisi')
            ->assertDontSee('value="open_box"', false)
            ->assertDontSee('ui.filters.all_conditions', false);

        $this->actingAs($this->user())
            ->withSession(['locale' => 'en'])
            ->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSeeText('All conditions')
            ->assertDontSee('ui.filters.all_conditions', false);
    }

    public function test_listing_kpis_distinguish_listing_records_from_unique_products(): void
    {
        config(['listing-discovery.target_active_listings' => 3]);
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        $mappedProduct = $this->product('NVIDIA GeForce RTX 3070', 'RTX 3070');
        $this->product('NVIDIA GeForce RTX 3080', 'RTX 3080');

        $this->listing($platform, $mappedProduct, Listing::CONDITION_USED);
        $this->listing($platform, $mappedProduct, Listing::CONDITION_NEW)
            ->update(['product_id' => null]);
        $this->listing($platform, $mappedProduct, Listing::CONDITION_OPEN_BOX)
            ->update(['stock_status' => Listing::STOCK_OUT_OF_STOCK]);

        $response = $this->actingAs($this->user())
            ->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSeeText('Angka Listing menghitung jumlah data listing marketplace.')
            ->assertSeeText('Listing Tersedia')
            ->assertSeeText('Listing Terpetakan')
            ->assertSeeText('Listing Belum Terpetakan')
            ->assertSeeText('Product Tanpa Listing Tersedia')
            ->assertSeeText('Product di Bawah Target Listing')
            ->assertSeeText('Listing aktif yang dapat digunakan dalam pencarian.')
            ->assertSeeText('Listing tersedia yang sudah terhubung ke Product.')
            ->assertSeeText('Listing tersedia yang belum memiliki Product.')
            ->assertSeeText('Product unik yang belum memiliki satu pun Listing tersedia.')
            ->assertSeeText('Product unik yang jumlah Listing tersedianya masih di bawah target 3.')
            ->assertSee('href="'.route('admin.listings.unmatched').'"', false)
            ->assertSee(route('admin.products.index', [
                'status' => 'active',
                'listing_status' => 'without_active',
            ]));

        $this->assertSame([
            'active' => 2,
            'matched' => 1,
            'unmatched' => 1,
            'without_active' => 1,
            'needing' => 2,
        ], $response->viewData('coverage'));
        $this->assertSame(3, $response->viewData('listingTarget'));
    }

    /** @param list<list<string>> $rows */
    private function upload(array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fputcsv($stream, MarketplaceCsvImportService::HEADERS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('listings.csv', (string) $content);
    }

    /** @return list<string> */
    private function validRow(): array
    {
        return [
            'olx', 'WEB-RTX3060-001', 'VGA NVIDIA RTX 3060 12GB',
            'Standalone graphics card.', '3100000', 'IDR', 'used',
            'Seller Jakarta', 'unknown', 'Jakarta',
            'https://www.olx.co.id/item/vga-rtx-3060-iid-991100', '',
            'in_stock', 'full_price', '', '2026-09-10T10:00:00+07:00',
        ];
    }

    private function product(string $name, string $model): Product
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'gpu'],
            ['name' => 'GPU', 'is_active' => true],
        );

        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'brand' => 'NVIDIA',
            'model' => $model,
            'variant' => '12 GB Desktop',
            'normalized_key' => Product::makeNormalizedKey(
                $name,
                'NVIDIA',
                $model,
                '12 GB Desktop',
            ),
            'is_active' => true,
        ]);
    }

    private function listing(
        Platform $platform,
        Product $product,
        string $condition,
    ): Listing {
        return Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => 'condition-'.$condition,
            'source_fingerprint' => hash('sha256', 'condition-'.$condition),
            'title' => 'Listing '.$condition,
            'price' => 2_000_000,
            'currency' => 'IDR',
            'condition' => $condition,
            'seller_type' => Listing::SELLER_UNKNOWN,
            'product_url' => 'https://www.olx.co.id/item/'.$condition,
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
    }

    private function platforms(): void
    {
        foreach (Platform::MVP_SLUGS as $slug) {
            Platform::query()->create([
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
    }

    private function user(bool $admin = true): User
    {
        return User::query()->create([
            'name' => $admin ? 'Admin' : 'User',
            'email' => ($admin ? 'admin' : 'user').'-'.uniqid().'@example.test',
            'password' => 'password',
            'is_admin' => $admin,
        ]);
    }
}
