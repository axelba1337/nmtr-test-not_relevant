<?php

namespace App\Services\Marketplace;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListingResearchService
{
    /** @var list<string> */
    public const TARGET_HEADERS = [
        'product_id',
        'name',
        'category',
        'brand',
        'model',
        'variant',
        'current_active_listings',
        'target_listings',
    ];

    public function __construct(
        private readonly ListingCoverageService $coverage,
    ) {}

    public function targetsQuery(): Builder
    {
        return $this->coverage->needingQuery($this->targetListings());
    }

    /** @return Collection<int, Product> */
    public function targetPreview(int $limit = 8): Collection
    {
        return $this->targetsQuery()
            ->limit(min(20, max(1, $limit)))
            ->get();
    }

    public function targetCount(): int
    {
        return $this->coverage->countNeeding($this->targetListings());
    }

    public function promptMaximumProducts(): int
    {
        return max(
            1,
            (int) config('listing-discovery.research_prompt_max_products', 25),
        );
    }

    /** @return Collection<int, Product> */
    public function promptTargets(): Collection
    {
        return $this->targetsQuery()
            ->limit($this->promptMaximumProducts())
            ->get();
    }

    public function minimumListings(): int
    {
        return max(
            1,
            (int) config('listing-discovery.minimum_active_listings', 2),
        );
    }

    public function targetListings(): int
    {
        return max(
            $this->minimumListings(),
            (int) config('listing-discovery.target_active_listings', 3),
        );
    }

    public function prompt(): string
    {
        $region = trim((string) config('listing-discovery.region', 'Indonesia'));
        $region = $region !== '' ? $region : 'Indonesia';
        $maximumNew = max(
            1,
            (int) config('listing-discovery.max_new_listings_per_product', 3),
        );
        $maximumPerPlatform = max(
            1,
            (int) config('listing-discovery.max_per_platform_per_product', 2),
        );
        $promptMaximumProducts = $this->promptMaximumProducts();
        $products = $this->promptTargets()
            ->map(fn (Product $product): string => implode(' | ', [
                $product->id,
                $this->plain($product->name),
                $this->plain($product->category?->name),
                $this->plain($product->brand),
                $this->plain($product->model),
                $this->plain($product->variant),
                $product->active_listings_count,
                $this->targetListings(),
            ]))
            ->implode("\n");
        $products = $products !== '' ? $products : 'No target products.';
        $header = implode(',', MarketplaceCsvImportService::HEADERS);

        return <<<PROMPT
Research verified marketplace listings only for the supplied canonical Neomatra Products.

PRODUCT TARGET RULES:
- Region: {$region}.
- Research ONLY the supplied canonical Neomatra Products. This prompt contains at most {$promptMaximumProducts} high-priority Products with the lowest current Listing coverage.
- Actively use web browsing or web search. For EACH target Product, actively check all five supported marketplaces before finalizing candidate Listings: Tokopedia, Shopee, OLX, Facebook Marketplace, and Carousell Indonesia.
- Do not stop searching because Shopee or another marketplace already produced valid results.
- Search only the canonical Products listed below, using their name, category, brand, model, and variant.
- Do not create or propose new Products, ProductAliases, Categories, or product identities.
- Do not substitute an unrelated Product.
- Return only real Listings supported by factual source evidence.
- Accept only direct, verified marketplace Listing pages. Reject manufacturer pages, blogs, reviews, price-comparison pages, search-result pages, category pages, wanted/buying posts, services, unrelated accessories, and full PCs when the target is a standalone component.
- Maximum new Listings per Product: {$maximumNew}.
- Maximum Listings from one Platform per Product: {$maximumPerPlatform}.
- If verified Listings exist across different marketplaces, prefer source diversity, for example one verified Listing from each source instead of several from one source.
- Only return multiple Listings from the same marketplace after attempting the other four supported marketplaces.
- Do not let one marketplace dominate the output merely because its results are easier to find.
- These limits are safety caps, not equal marketplace quotas. Never fabricate rows to create balance.
- If another marketplace has no verifiable Listing, return fewer rows instead of fabricating data.

MARKETPLACE-SPECIFIC SEARCH GUIDANCE:
- Tokopedia: search the tokopedia.com domain.
- Shopee: search the shopee.co.id domain.
- OLX: search the olx.co.id domain.
- Facebook Marketplace: search direct facebook.com/marketplace/item Listing pages.
- Carousell Indonesia: search id.carousell.com or a verified Indonesian Listing on carousell.com.
- These are search hints, not a reason to accept a search-results page. Every final product_url must be a direct verified Listing URL.

FIELD-SPECIFIC RULES:
- platform: use only tokopedia, shopee, olx, facebook, or carousell.
- Required fields: platform, title, price, and product_url. Never fabricate required data.
- external_listing_id: include it only when it can be verified from the source; otherwise leave it blank.
- price: use a positive integer only, with no Rp prefix and no dots or commas. Example: 3200000.
- currency: use IDR.
- condition: use only new, used, open_box, refurbished, ex_display, or unknown.
- product_url: use a plain direct marketplace Listing URL, never Markdown. Do not use a search, category, manufacturer, blog, or review page.
- stock_status: use only in_stock, out_of_stock, or unknown.
- price_type: use only full_price, installment, down_payment, or unknown when explicitly verified; otherwise leave it blank.
- source_published_at: use an ISO-compatible value only when verified; otherwise leave it empty.
- Keep unavailable optional data empty. Use unknown only where this CSV contract permits it.
- Require a visible full price. Do not use an installment amount or down payment as a full price.
- Never guess location, condition, warranty, seller information, stock status, or external_listing_id.

Condition mapping must follow explicit Listing evidence only:
- new / baru / sealed => new
- used / second / bekas => used
- open box => open_box
- refurbished => refurbished
- ex-display / display unit => ex_display
- unclear => unknown
Do not infer condition from seller type, marketplace name, or a cheap price. A store seller does not imply new. OLX or Facebook does not imply used.

TEXT FIELD SAFETY:
- Keep title, description, seller_name, location, and warranty concise and factual.
- Do not insert newline characters or tabs into any field.
- Properly quote fields containing commas using standard CSV double quotes.
- Escape a double quote inside a field by doubling it: "".
- Avoid decorative symbols and emoji.

FINAL RESPONSE FORMAT:
- Put the complete Listing result inside exactly ONE fenced CSV code block labeled csv.
- The first line inside that single CSV code block must be the exact 16-column Neomatra header shown below.
- Do not split Listing data into multiple code blocks.
- Do not place explanations inside the CSV code block or around it.
- Do not output a Markdown table, JSON, or bullet lists containing Listing data.
- Use exactly the required 16 columns in the exact order.
- Every Listing must occupy exactly one CSV record and one physical line.
- Do not insert line breaks inside any field.
- Quote fields and escape quotes according to standard CSV rules.
- product_url must be a plain direct URL, never Markdown.
- If optional data is unavailable, leave the field empty.
- Every row must contain exactly 16 fields.
- Do not output duplicate product_url values.
- Do not invent missing data, fake product_url values, or placeholder Listings.
- Do not invent a download URL.
- Before answering, internally validate that the header, field count, URLs, prices, duplicate URLs, and one-line record format satisfy this contract.
- If a verified Listing cannot be found, return fewer rows instead of fabricating data.

Use exactly this header:
{$header}

DOWNLOADABLE FILE WHEN SUPPORTED:
- If your AI environment supports creating downloadable files, also create a file named marketplace-listings.csv containing exactly the same CSV data as the single CSV code block.
- If downloadable-file creation is not supported, returning the single CSV code block is sufficient.
- Never create or claim a fake download URL.

Canonical target products (product_id | canonical name | category | brand | model | variant | current_active_listings | target_listings):
{$products}
PROMPT;
    }

    private function plain(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '-';
        }

        $value = preg_replace('/[\r\n|]+/u', ' ', trim((string) $value)) ?? '';

        return $value !== '' ? $value : '-';
    }
}
