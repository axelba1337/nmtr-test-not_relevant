<?php

namespace App\Http\Controllers;

use App\Data\AI\ParsedIntent;
use App\Models\Product;
use App\Services\Search\ListingSearchService;
use Illuminate\View\View;

final class ProductComparisonController extends Controller
{
    public function __construct(
        private readonly ListingSearchService $listingSearch,
    ) {}

    public function __invoke(Product $product): View
    {
        $product->loadMissing('category');
        abort_unless(
            $product->is_active && $product->category?->is_active,
            404,
        );
        $intent = new ParsedIntent(
            (string) $product->name,
            $product->category?->name,
            $product->brand,
            $product->model,
            $product->variant,
            $product->sku_or_part_number,
            null,
            null,
            [],
            [],
            [],
            'buyer',
            false,
            null,
        );
        $listings = $this->listingSearch->search([[
            'product' => $product,
            'similarity' => 1.0,
            'matched_via' => 'canonical_product',
        ]], $intent);
        $listings = array_map(static function (array $listing): array {
            $listing['source_url'] = route('listings.open', $listing['id']);

            return $listing;
        }, $listings);

        return view('products.compare', compact('product', 'listings'));
    }
}
