<?php

namespace Tests\Feature;

use App\Data\AI\ParsedIntent;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Search\ListingSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListingConditionFilterTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config(['neomatra.search.result_top_k' => 20]);

        $category = Category::query()->create([
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
        ]);
        $this->product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'NVIDIA GeForce RTX 3060',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'is_active' => true,
        ]);
        $platform = Platform::query()->create([
            'name' => 'Tokopedia',
            'slug' => 'tokopedia',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'base_url' => 'https://www.tokopedia.com',
            'is_active' => true,
            'sync_enabled' => false,
            'status' => Platform::STATUS_READY,
        ]);

        foreach (Listing::conditions() as $condition) {
            Listing::query()->create([
                'platform_id' => $platform->id,
                'product_id' => $this->product->id,
                'external_listing_id' => 'condition-'.$condition,
                'source_fingerprint' => hash('sha256', 'condition-'.$condition),
                'title' => 'RTX 3060 '.$condition,
                'price' => 2_500_000,
                'currency' => 'IDR',
                'condition' => $condition,
                'condition_source' => Listing::CONDITION_SOURCE_SELLER,
                'seller_name' => 'Condition Test Seller',
                'location' => 'Jakarta',
                'product_url' => 'https://www.tokopedia.com/neomatra/condition-'.$condition,
                'stock_status' => Listing::STOCK_IN_STOCK,
                'price_type' => Listing::PRICE_FULL,
                'raw_payload' => ['connector' => 'manual-import'],
                'is_active' => true,
            ]);
        }
    }

    public function test_public_condition_filter_values_are_deliberately_simple(): void
    {
        $this->assertSame([
            Listing::CONDITION_NEW,
            Listing::CONDITION_USED,
            Listing::CONDITION_UNKNOWN,
        ], Listing::publicConditionFilters());
        $this->assertSame([
            Listing::CONDITION_NEW,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_USED,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_EX_DISPLAY,
            Listing::CONDITION_UNKNOWN,
        ], Listing::conditions());
    }

    public function test_condition_filters_map_to_the_expected_listing_values(): void
    {
        $this->assertConditions([Listing::CONDITION_NEW], Listing::CONDITION_NEW);
        $this->assertConditions([
            Listing::CONDITION_USED,
            Listing::CONDITION_OPEN_BOX,
            Listing::CONDITION_REFURBISHED,
            Listing::CONDITION_EX_DISPLAY,
        ], Listing::CONDITION_USED);
        $this->assertConditions([Listing::CONDITION_UNKNOWN], Listing::CONDITION_UNKNOWN);
        $this->assertConditions(Listing::conditions(), null);
    }

    public function test_explicit_detailed_condition_keeps_its_exact_search_behavior(): void
    {
        $this->assertConditions([Listing::CONDITION_OPEN_BOX], Listing::CONDITION_OPEN_BOX);
        $this->assertConditions([Listing::CONDITION_REFURBISHED], Listing::CONDITION_REFURBISHED);
        $this->assertConditions([Listing::CONDITION_EX_DISPLAY], Listing::CONDITION_EX_DISPLAY);
    }

    /** @param list<string> $expected */
    private function assertConditions(array $expected, ?string $condition): void
    {
        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060',
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => null,
            'sku_or_part_number' => null,
            'budget_min' => null,
            'budget_max' => null,
            'conditions' => $condition === null ? [] : [$condition],
            'locations' => [],
            'platforms' => [],
            'needs_clarification' => false,
            'clarification_question' => null,
            'intent_type' => ParsedIntent::TYPE_PRODUCT_SEARCH,
        ]);
        $this->assertNotNull($intent);

        $results = app(ListingSearchService::class)->search([
            [
                'product' => $this->product,
                'similarity' => 1.0,
                'matched_via' => 'deterministic_product',
            ],
        ], $intent);

        $this->assertEqualsCanonicalizing(
            $expected,
            array_column($results, 'condition'),
        );
    }
}
