<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ListingCoverageService;
use App\Services\Marketplace\ListingResearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListingCoverageController extends Controller
{
    private const PER_PAGE = 100;

    public function __construct(
        private readonly ListingCoverageService $coverage,
        private readonly ListingResearchService $research,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $products = $this->research->targetsQuery()
            ->paginate(self::PER_PAGE)
            ->withQueryString();
        $promptMaximum = $this->research->promptMaximumProducts();

        return view('admin.listings.coverage', [
            'products' => $products,
            'metrics' => $this->coverage->metrics(),
            'minimum' => $this->research->minimumListings(),
            'target' => $this->research->targetListings(),
            'promptMaximum' => $promptMaximum,
            'promptBatchCount' => min($products->total(), $promptMaximum),
            'stillNeededByProduct' => $products->getCollection()
                ->mapWithKeys(fn ($product): array => [
                    $product->id => $this->coverage->stillNeeded(
                        (int) $product->active_listings_count,
                    ),
                ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->ensureAdmin($request);

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fputcsv($output, ListingResearchService::TARGET_HEADERS, ',', '"', '');
            $target = $this->research->targetListings();
            $this->research->targetsQuery()->chunkById(
                100,
                static function ($products) use ($output, $target): void {
                    foreach ($products as $product) {
                        fputcsv($output, [
                            $product->id,
                            $product->name,
                            $product->category?->name,
                            $product->brand,
                            $product->model,
                            $product->variant,
                            $product->active_listings_count,
                            $target,
                        ], ',', '"', '');
                    }
                },
                'products.id',
                'id',
            );
            fclose($output);
        }, 'neomatra-products-needing-listings.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function researchPrompt(Request $request): StreamedResponse
    {
        $this->ensureAdmin($request);
        $contents = $this->research->prompt();

        return response()->streamDownload(
            static function () use ($contents): void {
                echo $contents;
            },
            'neomatra-listing-research-prompt.md',
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
