<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductCsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductImportController extends Controller
{
    private const PREVIEW_TTL_MINUTES = 30;

    public function __construct(
        private readonly ProductCsvImportService $csv,
    ) {}

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.products.import');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->ensureAdmin($request);

        return response()->streamDownload(static function (): void {
            $output = fopen('php://output', 'wb');

            if ($output !== false) {
                fputcsv(
                    $output,
                    ProductCsvImportService::HEADERS,
                    ',',
                    '"',
                    '',
                );
                fclose($output);
            }
        }, 'neomatra-product-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function preview(Request $request): View
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'csv' => [
                'required',
                'file',
                'extensions:csv',
                'mimes:csv,txt',
                'max:2048',
            ],
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

        return view('admin.products.import', [
            'preview' => $preview,
            'previewToken' => $token,
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'preview_token' => [
                'required',
                'string',
                'size:64',
                'regex:/^[A-Za-z0-9]+$/',
            ],
            'approved_rows' => ['required', 'array', 'min:1'],
            'approved_rows.*' => ['required', 'integer', 'min:2', 'distinct'],
        ]);
        $preview = Cache::pull(
            $this->cacheKey($request, $validated['preview_token']),
        );

        if (! is_array($preview)
            || ! is_array($preview['rows'] ?? null)
            || ! is_array($preview['summary'] ?? null)) {
            throw ValidationException::withMessages([
                'preview_token' => __(
                    'ui.admin_pages.product_import.errors.expired',
                ),
            ]);
        }

        $allowedRows = collect($preview['rows'])
            ->pluck('row_number')
            ->map(static fn (mixed $row): int => (int) $row)
            ->all();
        $approvedRows = array_values(array_intersect(
            array_map('intval', $validated['approved_rows']),
            $allowedRows,
        ));

        if ($approvedRows === []) {
            throw ValidationException::withMessages([
                'approved_rows' => __(
                    'ui.admin_pages.product_import.errors.no_selection',
                ),
            ]);
        }

        $result = $this->csv->importRows($preview['rows'], $approvedRows);
        $result['skipped_existing'] += (int) (
            $preview['summary']['existing'] ?? 0
        );
        $result['skipped_duplicate'] += (int) (
            $preview['summary']['duplicate'] ?? 0
        );
        $result['invalid'] += (int) ($preview['summary']['invalid'] ?? 0);

        return redirect()->route('admin.products.import')
            ->with('product_import_result', $result)
            ->with(
                $result['failed'] > 0 ? 'warning' : 'success',
                __('ui.messages.admin.product_import.completed', $result),
            );
    }

    private function cacheKey(Request $request, string $token): string
    {
        return 'product-import:'
            .$request->user()->getAuthIdentifier()
            .':'.$token;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
