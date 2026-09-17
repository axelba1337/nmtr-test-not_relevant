<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\ListingCoverageService;
use App\Services\Marketplace\ListingResearchService;
use App\Services\Marketplace\MarketplaceCsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ListingCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_defaults_are_config_only_and_target_cannot_drop_below_minimum(): void
    {
        $this->assertSame(2, (int) config('listing-discovery.minimum_active_listings'));
        $this->assertSame(3, (int) config('listing-discovery.target_active_listings'));
        $this->assertSame(25, (int) config('listing-discovery.research_prompt_max_products'));

        $example = (string) file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^LISTING_DISCOVERY_MIN_ACTIVE_LISTINGS=2$/m', $example);
        $this->assertMatchesRegularExpression('/^LISTING_DISCOVERY_TARGET_ACTIVE_LISTINGS=3$/m', $example);
        $this->assertMatchesRegularExpression('/^LISTING_RESEARCH_PROMPT_MAX_PRODUCTS=25$/m', $example);

        foreach (['target_listing_count', 'minimum_listing_count', 'research_batch_size'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }

        config([
            'listing-discovery.minimum_active_listings' => 4,
            'listing-discovery.target_active_listings' => 2,
            'listing-discovery.research_prompt_max_products' => 17,
        ]);
        $coverage = app(ListingCoverageService::class);
        $research = app(ListingResearchService::class);

        $this->assertSame(4, $research->minimumListings());
        $this->assertSame(4, $research->targetListings());
        $this->assertSame(4, $coverage->target());
        $this->assertSame(17, $research->promptMaximumProducts());
        $this->assertSame(4, $coverage->stillNeeded(0));
        $this->assertSame(1, $coverage->stillNeeded(3));
        $this->assertSame(0, $coverage->stillNeeded(4));
        $this->assertSame(0, $coverage->stillNeeded(8));
    }

    public function test_coverage_prioritizes_zero_then_below_minimum_and_skips_sufficient_or_inactive_products(): void
    {
        config([
            'listing-discovery.minimum_active_listings' => 2,
            'listing-discovery.target_active_listings' => 2,
        ]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);
        $platform = Platform::query()->create([
            'name' => 'OLX', 'slug' => 'olx',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import', 'status' => Platform::STATUS_READY,
            'is_active' => true, 'sync_enabled' => false,
        ]);
        $zero = $this->product($category, 'Zero Coverage', true);
        $below = $this->product($category, 'Below Minimum', true);
        $sufficient = $this->product($category, 'Sufficient', true);
        $inactive = $this->product($category, 'Inactive', false);
        $this->listing($platform, $below, 'below-1');
        $this->listing($platform, $sufficient, 'sufficient-1');
        $this->listing($platform, $sufficient, 'sufficient-2');

        $coverage = app(ListingCoverageService::class);
        $targets = $coverage->needingQuery()->get();

        $this->assertSame([$zero->id, $below->id], $targets->pluck('id')->all());
        $this->assertSame([0, 1], $targets->pluck('active_listings_count')->all());
        $this->assertSame([
            'total_active_listings' => 3,
            'ready_to_use_listings' => 3,
            'total_active_products' => 3,
            'without_ready_to_use_listings' => 1,
            'below_target' => 2,
        ], $coverage->metrics());
        $this->assertFalse($targets->contains('id', $sufficient->id));
        $this->assertFalse($targets->contains('id', $inactive->id));

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'coverage@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);
        $this->actingAs($admin)
            ->get(route('admin.listings.coverage'))
            ->assertOk()
            ->assertSeeText('Zero Coverage')
            ->assertSeeText('Below Minimum')
            ->assertDontSeeText('Sufficient');
        $export = $this->actingAs($admin)
            ->get(route('admin.listings.coverage.export'))
            ->assertOk()
            ->assertDownload('neomatra-products-needing-listings.csv');
        $content = str_replace("\r\n", "\n", $export->streamedContent());
        $this->assertStringStartsWith(
            "product_id,name,category,brand,model,variant,current_active_listings,target_listings\n",
            $content,
        );
        $this->assertStringContainsString('Zero Coverage', $content);
        $this->assertStringContainsString(',0,2', $content);
        $this->assertStringNotContainsString('Sufficient', $content);
    }

    public function test_research_prompt_is_dynamic_constrained_and_contains_no_secrets(): void
    {
        config([
            'listing-discovery.minimum_active_listings' => 2,
            'listing-discovery.target_active_listings' => 5,
            'listing-discovery.research_prompt_max_products' => 10,
            'listing-discovery.max_new_listings_per_product' => 3,
            'listing-discovery.max_per_platform_per_product' => 2,
            'listing-discovery.region' => 'Indonesia',
            'listing-discovery.tavily.api_key' => 'DO_NOT_LEAK_DISCOVERY_KEY',
            'database.connections.mysql.password' => 'DO_NOT_LEAK_DB_PASSWORD',
        ]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);
        $product = $this->product($category, 'NVIDIA GeForce RTX 3060', true);
        $product->update([
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB Desktop',
        ]);

        $prompt = app(ListingResearchService::class)->prompt();

        $this->assertStringContainsString('Indonesia', $prompt);
        $this->assertStringContainsString('Tokopedia, Shopee, OLX, Facebook Marketplace, and Carousell', $prompt);
        $this->assertStringContainsString('For EACH target Product, actively check all five supported marketplaces', $prompt);
        $this->assertStringContainsString('Do not stop searching because Shopee', $prompt);
        $this->assertStringContainsString($product->id.' | NVIDIA GeForce RTX 3060 | GPU | NVIDIA | RTX 3060 | 12 GB Desktop | 0 | 5', $prompt);
        $this->assertStringContainsString('Maximum new Listings per Product: 3', $prompt);
        $this->assertStringContainsString('Maximum Listings from one Platform per Product: 2', $prompt);
        $this->assertStringContainsString('prefer source diversity', $prompt);
        $this->assertStringContainsString('not equal marketplace quotas', $prompt);
        $this->assertStringContainsString('Do not let one marketplace dominate the output', $prompt);
        $this->assertStringContainsString('tokopedia.com', $prompt);
        $this->assertStringContainsString('shopee.co.id', $prompt);
        $this->assertStringContainsString('olx.co.id', $prompt);
        $this->assertStringContainsString('facebook.com/marketplace/item', $prompt);
        $this->assertStringContainsString('id.carousell.com', $prompt);
        $this->assertStringContainsString('Do not infer condition from seller type, marketplace name, or a cheap price.', $prompt);
        $this->assertCount(16, MarketplaceCsvImportService::HEADERS);
        $this->assertStringContainsString(implode(',', MarketplaceCsvImportService::HEADERS), $prompt);
        $this->assertStringContainsString('FINAL RESPONSE FORMAT:', $prompt);
        $this->assertStringContainsString('exactly ONE fenced CSV code block labeled csv', $prompt);
        $this->assertStringContainsString('Do not split Listing data into multiple code blocks.', $prompt);
        $this->assertStringContainsString('Do not output a Markdown table, JSON, or bullet lists', $prompt);
        $this->assertStringContainsString('one physical line', $prompt);
        $this->assertStringContainsString('product_url must be a plain direct URL, never Markdown.', $prompt);
        $this->assertStringContainsString('Escape a double quote inside a field by doubling it: "".', $prompt);
        $this->assertStringContainsString('Every row must contain exactly 16 fields.', $prompt);
        $this->assertStringContainsString('return fewer rows instead of fabricating data.', $prompt);
        $this->assertStringContainsString('platform: use only tokopedia, shopee, olx, facebook, or carousell.', $prompt);
        $this->assertStringContainsString('also create a file named marketplace-listings.csv', $prompt);
        $this->assertStringContainsString('If downloadable-file creation is not supported', $prompt);
        $this->assertStringContainsString('Never create or claim a fake download URL.', $prompt);
        $this->assertStringNotContainsString('exactly 100', $prompt);
        $this->assertStringNotContainsString('DO_NOT_LEAK_DISCOVERY_KEY', $prompt);
        $this->assertStringNotContainsString('DO_NOT_LEAK_DB_PASSWORD', $prompt);
    }

    public function test_research_prompt_caps_priority_targets_while_full_target_export_remains_complete(): void
    {
        config([
            'listing-discovery.minimum_active_listings' => 2,
            'listing-discovery.target_active_listings' => 5,
            'listing-discovery.research_prompt_max_products' => 3,
        ]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);
        $platform = Platform::query()->create([
            'name' => 'OLX', 'slug' => 'olx',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import', 'status' => Platform::STATUS_READY,
            'is_active' => true, 'sync_enabled' => false,
        ]);
        $withListing = $this->product($category, 'A Product With Listing', true);
        $zeroAlpha = $this->product($category, 'B Zero Alpha', true);
        $zeroBeta = $this->product($category, 'C Zero Beta', true);
        $zeroGamma = $this->product($category, 'D Zero Gamma', true);
        $this->listing($platform, $withListing, 'priority-existing-1');

        $research = app(ListingResearchService::class);
        $this->assertSame(3, $research->promptMaximumProducts());
        $this->assertSame(
            [$zeroAlpha->id, $zeroBeta->id, $zeroGamma->id],
            $research->promptTargets()->pluck('id')->all(),
        );

        $prompt = $research->prompt();
        $this->assertStringContainsString('B Zero Alpha', $prompt);
        $this->assertStringContainsString('C Zero Beta', $prompt);
        $this->assertStringContainsString('D Zero Gamma', $prompt);
        $this->assertStringNotContainsString('A Product With Listing', $prompt);

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'research-export@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);
        $export = $this->actingAs($admin)
            ->get(route('admin.listings.coverage.export'))
            ->assertOk()
            ->assertDownload('neomatra-products-needing-listings.csv')
            ->streamedContent();

        foreach ([$withListing, $zeroAlpha, $zeroBeta, $zeroGamma] as $product) {
            $this->assertStringContainsString($product->name, $export);
        }
    }

    public function test_coverage_ui_uses_real_target_and_caps_prompt_batch_at_twenty_five(): void
    {
        config([
            'listing-discovery.minimum_active_listings' => 2,
            'listing-discovery.target_active_listings' => 3,
            'listing-discovery.research_prompt_max_products' => 25,
        ]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);
        $platform = Platform::query()->create([
            'name' => 'OLX', 'slug' => 'olx',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import', 'status' => Platform::STATUS_READY,
            'is_active' => true, 'sync_enabled' => false,
        ]);

        $zeroProducts = collect(range(1, 27))->map(
            fn (int $index): Product => $this->product(
                $category,
                sprintf('Zero Product %03d', $index),
                true,
            ),
        );
        $partial = $this->product($category, 'Partial Coverage', true);
        $reached = $this->product($category, 'Reached Target', true);
        $this->listing($platform, $partial, 'partial-1');
        $this->listing($platform, $partial, 'partial-out-of-stock')
            ->update(['stock_status' => Listing::STOCK_OUT_OF_STOCK]);
        $this->listing($platform, $partial, 'partial-inactive')
            ->update(['is_active' => false]);
        foreach (range(1, 3) as $index) {
            $this->listing($platform, $reached, 'reached-'.$index);
        }

        $research = app(ListingResearchService::class);
        $this->assertSame(28, $research->targetCount());
        $this->assertSame(25, $research->promptTargets()->count());
        $this->assertSame('Zero Product 001', $research->promptTargets()->first()?->name);
        $this->assertFalse($research->promptTargets()->contains('id', $zeroProducts->last()->id));
        $this->assertFalse($research->targetsQuery()->get()->contains('id', $reached->id));

        $prompt = $research->prompt();
        $this->assertStringContainsString('Zero Product 025', $prompt);
        $this->assertStringNotContainsString('Zero Product 026', $prompt);
        $this->assertStringNotContainsString('Partial Coverage', $prompt);

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'coverage-ux@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);
        $coveragePage = $this->actingAs($admin)
            ->get(route('admin.listings.coverage'))
            ->assertOk()
            ->assertSeeText('Target coverage adalah jumlah ideal Listing Siap Digunakan untuk setiap Product')
            ->assertSeeText('Target saat ini: 3 Listing Siap Digunakan per Product.')
            ->assertSeeText('Total Product Aktif')
            ->assertSeeText('Product Tanpa Listing Siap Digunakan')
            ->assertSeeText('Product di Bawah Target Listing')
            ->assertSeeText('Target Coverage')
            ->assertSeeText('Coverage dihitung per Product menggunakan Listing Siap Digunakan.')
            ->assertSeeText('25 Product prioritas dari 28 Product yang masih berada di bawah target.')
            ->assertSeeText('Prompt riset hanya mengambil maksimal 25 Product dalam satu batch.')
            ->assertSeeText('Listing Siap Digunakan')
            ->assertSeeText('Target')
            ->assertSeeText('Masih Dibutuhkan')
            ->assertDontSeeText('Listing Tersedia')
            ->assertDontSeeText('Listing Tersedia Belum Terpetakan')
            ->assertDontSeeText('Reached Target');
        $this->assertSame(3, $coveragePage->viewData('target'));
        $this->assertSame(25, $coveragePage->viewData('promptBatchCount'));
        $this->assertSame([
            'total_active_listings' => 5,
            'ready_to_use_listings' => 4,
            'total_active_products' => 29,
            'without_ready_to_use_listings' => 27,
            'below_target' => 28,
        ], $coveragePage->viewData('metrics'));
        $this->assertMatchesRegularExpression(
            '/<strong>Zero Product 001<\/strong>.*?<td>GPU<\/td><td>0<\/td><td>3<\/td><td>3<\/td>/s',
            $coveragePage->getContent(),
        );
        $this->assertMatchesRegularExpression(
            '/<strong>Partial Coverage<\/strong>.*?<td>GPU<\/td><td>1<\/td><td>3<\/td><td>2<\/td>/s',
            $coveragePage->getContent(),
        );

        $this->actingAs($admin)
            ->get(route('admin.listings.import'))
            ->assertOk()
            ->assertSeeText('Batch Riset AI')
            ->assertSeeText('25 dari 28 Product')
            ->assertSeeText('Lihat Semua Product');

        $listingPage = $this->actingAs($admin)
            ->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSeeText('Product aktif yang jumlah Listing Siap Digunakannya masih di bawah target 3.');
        $this->assertSame(3, $listingPage->viewData('listingTarget'));
        $this->assertSame(28, $listingPage->viewData('coverage')['below_target']);

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.listings.coverage'))
            ->assertOk()
            ->assertSeeText('Total Active Products')
            ->assertSeeText('Products Without Ready-to-Use Listings')
            ->assertSeeText('Products Below Listing Target')
            ->assertSeeText('25 priority Products out of 28 Products that remain below target.')
            ->assertSeeText('Ready-to-Use Listings')
            ->assertDontSeeText('Available Listings')
            ->assertDontSeeText('Available Unmapped Listings');

        $export = $this->actingAs($admin)
            ->get(route('admin.listings.coverage.export'))
            ->assertOk()
            ->assertDownload('neomatra-products-needing-listings.csv')
            ->streamedContent();
        $this->assertStringContainsString('Zero Product 027', $export);
        $this->assertStringContainsString('Partial Coverage', $export);
        $this->assertStringNotContainsString('Reached Target', $export);
        $this->assertCount(29, preg_split('/\r\n|\n|\r/', trim($export)));
    }

    public function test_research_prompt_download_is_markdown_and_matches_the_copy_source(): void
    {
        config(['listing-discovery.research_prompt_max_products' => 10]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);
        $this->product($category, 'NVIDIA GeForce RTX 3070', true);
        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'research-download@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);

        $page = $this->actingAs($admin)
            ->get(route('admin.listings.import'))
            ->assertOk()
            ->assertSee('data-copy-target="#listing-research-prompt"', false)
            ->assertSeeText('Unduh Prompt .MD')
            ->assertSeeText('A. Fungsi prompt')
            ->assertSeeText('B. Cara menggunakan')
            ->assertSeeText('C. Prompt yang dibuat')
            ->assertSeeText('D. Aksi');
        $copySource = $page->viewData('researchPrompt');

        $download = $this->actingAs($admin)
            ->get(route('admin.listings.coverage.research-prompt'))
            ->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8')
            ->assertDownload('neomatra-listing-research-prompt.md');

        $this->assertSame($copySource, $download->streamedContent());
    }

    public function test_coverage_uses_compact_pagination_without_svg_and_preserves_query_string(): void
    {
        config([
            'listing-discovery.minimum_active_listings' => 2,
            'listing-discovery.target_active_listings' => 5,
        ]);
        $category = Category::query()->create([
            'name' => 'GPU', 'slug' => 'gpu', 'is_active' => true,
        ]);

        foreach (range(1, 101) as $index) {
            $this->product($category, sprintf('Coverage Product %03d', $index), true);
        }

        $admin = User::query()->create([
            'name' => 'Admin', 'email' => 'coverage-pages@example.test',
            'password' => 'password', 'is_admin' => true,
        ]);
        $firstPage = $this->actingAs($admin)
            ->get(route('admin.listings.coverage', ['source' => 'uat']))
            ->assertOk()
            ->assertSeeText('Kembali ke Impor CSV')
            ->assertSee('href="'.route('admin.listings.import').'"', false)
            ->assertSeeText('1–100 dari 101')
            ->assertSeeText('Berikutnya')
            ->assertSee('source=uat', false)
            ->assertDontSee('<svg', false);

        $this->assertStringContainsString('source=uat', $firstPage->viewData('products')->nextPageUrl());
        $this->actingAs($admin)
            ->get(route('admin.listings.coverage', ['source' => 'uat', 'page' => 2]))
            ->assertOk()
            ->assertSeeText('101–101 dari 101')
            ->assertSeeText('Sebelumnya')
            ->assertSee('source=uat', false)
            ->assertDontSee('<svg', false);

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.listings.coverage'))
            ->assertOk()
            ->assertSeeText('Back to CSV Import')
            ->assertDontSee('ui.admin_pages.listing_coverage.back_to_import', false);
    }

    private function product(Category $category, string $name, bool $active): Product
    {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'normalized_key' => Product::makeNormalizedKey($name),
            'is_active' => $active,
        ]);
    }

    private function listing(Platform $platform, Product $product, string $key): Listing
    {
        return Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => $key,
            'source_fingerprint' => hash('sha256', $key),
            'title' => $key,
            'price' => 1_000_000,
            'condition' => Listing::CONDITION_USED,
            'product_url' => 'https://www.olx.co.id/item/'.$key,
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
    }
}
