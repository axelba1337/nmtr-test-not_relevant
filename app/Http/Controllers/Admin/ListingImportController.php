<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\ListingResearchService;
use App\Services\Marketplace\MarketplaceCsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListingImportController extends Controller
{
    private const PREVIEW_TTL_MINUTES = 30;

    public function __construct(
        private readonly MarketplaceCsvImportService $csv,
        private readonly ListingResearchService $research,
    ) {}

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.listings.import', $this->workflowData());
    }

    public function template(Request $request): StreamedResponse
    {
        $this->ensureAdmin($request);

        return response()->streamDownload(static function (): void {
            $output = fopen('php://output', 'wb');

            if ($output !== false) {
                fputcsv(
                    $output,
                    MarketplaceCsvImportService::HEADERS,
                    ',',
                    '"',
                    '',
                );
                fclose($output);
            }
        }, 'neomatra-marketplace-listings-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function preview(Request $request): View
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);
        $file = $validated['csv'];

        try {
            $preview = $this->csv->previewFile(
                (string) $file->getRealPath(),
                $file->getClientOriginalName(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'csv' => $exception->getMessage(),
            ]);
        }

        $token = Str::random(64);
        Cache::put($this->cacheKey($request, $token), [
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
        ], now()->addMinutes(self::PREVIEW_TTL_MINUTES));

        return view('admin.listings.import', [
            ...$this->workflowData(),
            'preview' => $preview,
            'previewToken' => $token,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'preview_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $preview = Cache::pull(
            $this->cacheKey($request, $validated['preview_token']),
        );

        if (! is_array($preview) || ! is_array($preview['rows'] ?? null)) {
            throw ValidationException::withMessages([
                'preview_token' => __('ui.admin_pages.listing_import.expired'),
            ]);
        }

        $result = $this->csv->importRows($preview['rows']);
        $counts = $result['counts'];
        $level = $counts['failed'] > 0 ? 'warning' : 'success';

        return redirect()->route('admin.listings.index')->with(
            $level,
            __('ui.messages.admin.listing.imported', [
                'seen' => $preview['summary']['rows'] ?? count($preview['rows']),
                'created' => $counts['created'],
                'updated' => $counts['updated'],
                'unchanged' => $counts['unchanged'],
                'rejected' => ($preview['summary']['rejected'] ?? 0)
                    + ($preview['summary']['duplicate'] ?? 0),
                'failed' => $counts['failed'],
                'matched' => $counts['matched'],
                'unmatched' => $counts['unmatched'],
            ]),
        );
    }

    /** @return array<string, mixed> */
    private function workflowData(): array
    {
        return [
            'targetProducts' => $this->research->targetPreview(),
            'targetProductCount' => $this->research->targetCount(),
            'targetListingCount' => $this->research->targetListings(),
            'researchPromptProductLimit' => $this->research->promptMaximumProducts(),
            'researchPrompt' => $this->research->prompt(),
        ];
    }

    private function cacheKey(Request $request, string $token): string
    {
        return 'listing-import:'.$request->user()->getAuthIdentifier().':'.$token;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
