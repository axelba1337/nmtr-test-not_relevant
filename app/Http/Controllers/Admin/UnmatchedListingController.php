<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LinkExistingProductRequest;
use App\Http\Requests\Admin\StoreCandidateProductRequest;
use App\Models\Listing;
use App\Models\Product;
use App\Services\Catalog\CatalogScope;
use App\Services\Catalog\UnmatchedListingReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UnmatchedListingController extends Controller
{
    public function __construct(
        private readonly CatalogScope $scope,
        private readonly UnmatchedListingReviewService $review,
    ) {}

    public function review(Request $request, Listing $listing): View
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
        abort_if($listing->product_id !== null, 404);
        $listing->load('platform:id,name,slug');

        return view('admin.listings.review-candidate', [
            'listing' => $listing,
            'suggestions' => $this->review->suggestions($listing),
            'categories' => $this->scope->categoryOptions(),
            'products' => Product::query()
                ->active()
                ->with('category.parent.parent')
                ->orderBy('name')
                ->get(['id', 'category_id', 'name', 'brand', 'model', 'variant'])
                ->filter(fn (Product $product): bool => $this->scope->supports(
                    $product->category,
                ))
                ->values(),
        ]);
    }

    public function approve(
        StoreCandidateProductRequest $request,
        Listing $listing,
    ): RedirectResponse {
        $result = $this->review->approveNew($listing, $request->validated());

        return redirect()->route('admin.listings.unmatched')
            ->with(
                $result['embedding_failed'] ? 'warning' : 'success',
                $result['embedding_failed']
                    ? __('ui.messages.admin.listing.candidate_approved_embedding_failed')
                    : __('ui.messages.admin.listing.candidate_approved'),
            );
    }

    public function link(
        LinkExistingProductRequest $request,
        Listing $listing,
    ): RedirectResponse {
        $product = Product::query()->findOrFail(
            $request->integer('product_id'),
        );
        $aliases = $this->review->parseAliases(
            $request->validated('aliases'),
        );
        $result = $this->review->linkExisting($listing, $product, $aliases);

        return redirect()->route('admin.listings.unmatched')
            ->with(
                $result['embedding_failed'] ? 'warning' : 'success',
                $result['embedding_failed']
                    ? __('ui.messages.admin.listing.linked_embedding_failed')
                    : __('ui.messages.admin.listing.linked'),
            );
    }
}
