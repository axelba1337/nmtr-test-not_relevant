<?php

namespace App\Services\Marketplace;

use App\Data\Marketplace\MarketplaceListingData;
use App\Models\Listing;
use App\Models\Platform;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SplFileObject;
use Throwable;

final class MarketplaceCsvImportService
{
    /** @var list<string> */
    public const HEADERS = [
        'platform',
        'external_listing_id',
        'title',
        'description',
        'price',
        'currency',
        'condition',
        'seller_name',
        'seller_type',
        'location',
        'product_url',
        'image_url',
        'stock_status',
        'price_type',
        'warranty',
        'source_published_at',
    ];

    /** @var array<string, list<string>> */
    public const PLATFORM_HOSTS = [
        'olx' => ['olx.co.id', 'www.olx.co.id'],
        'tokopedia' => ['tokopedia.com', 'www.tokopedia.com'],
        'shopee' => ['shopee.co.id', 'www.shopee.co.id'],
        'facebook' => ['facebook.com', 'www.facebook.com'],
        'carousell' => ['id.carousell.com', 'carousell.com'],
    ];

    public function __construct(
        private readonly MarketplaceListingIngestionService $ingestion,
        private readonly MarketplaceProductMatcher $products,
    ) {}

    /**
     * @return array{
     *     summary: array{rows:int,valid:int,rejected:int,duplicate:int,matched:int,unmatched:int},
     *     issues: list<string>,
     *     details: list<array{row_number:int,platform:?string,title:?string,product_mapping:?string,status:string,reason:?string}>,
     *     rows: list<array<string,mixed>>
     * }
     */
    public function previewFile(string $path, string $filename): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('CSV file is not readable.');
        }

        $file = new SplFileObject($path, 'rb');
        $file->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::DROP_NEW_LINE
            | SplFileObject::SKIP_EMPTY,
        );
        $file->setCsvControl(',', '"', '');
        $header = $file->fgetcsv();

        if (! is_array($header) || ! $this->validHeader($header)) {
            throw new InvalidArgumentException(
                'Header CSV tidak sesuai format marketplace import.',
            );
        }

        $records = [];

        while (! $file->eof()) {
            $row = $file->fgetcsv();
            $rowNumber = $file->key() + 1;

            if ($this->emptyRow($row)) {
                continue;
            }

            if (! is_array($row) || count($row) !== count(self::HEADERS)) {
                $records[] = [
                    'row_number' => $rowNumber,
                    'values' => null,
                    'reason' => 'jumlah kolom tidak valid',
                ];

                continue;
            }

            $records[] = [
                'row_number' => $rowNumber,
                'values' => array_combine(self::HEADERS, array_map(
                    static fn (mixed $value): ?string => is_string($value)
                        ? trim($value)
                        : null,
                    $row,
                )),
            ];
        }

        return $this->previewRecords($records, basename($filename));
    }

    /**
     * @param  list<array{row_number?:int,values:mixed,reason?:string}>  $records
     * @return array{
     *     summary: array{rows:int,valid:int,rejected:int,duplicate:int,matched:int,unmatched:int},
     *     issues: list<string>,
     *     details: list<array{row_number:int,platform:?string,title:?string,product_mapping:?string,status:string,reason:?string}>,
     *     rows: list<array<string,mixed>>
     * }
     */
    public function previewRecords(
        array $records,
        string $sourceName = 'marketplace-listings.csv',
    ): array {
        $platforms = Platform::query()
            ->whereIn('slug', array_keys(self::PLATFORM_HOSTS))
            ->get()
            ->keyBy('slug');
        $summary = [
            'rows' => 0,
            'valid' => 0,
            'rejected' => 0,
            'duplicate' => 0,
            'matched' => 0,
            'unmatched' => 0,
        ];
        $issues = [];
        $details = [];
        $validRows = [];
        $seenUrls = [];

        foreach ($records as $index => $record) {
            $summary['rows']++;
            $rowNumber = max(1, (int) ($record['row_number'] ?? $index + 2));
            $values = $record['values'] ?? null;

            if (! is_array($values)) {
                $summary['rejected']++;
                $issues[] = $this->issue(
                    $rowNumber,
                    (string) ($record['reason'] ?? 'record tidak valid'),
                );
                $details[] = $this->detail(
                    $rowNumber,
                    null,
                    'invalid',
                    (string) ($record['reason'] ?? 'record tidak valid'),
                );

                continue;
            }

            /** @var array<string, string|null> $values */
            $reason = $this->validateRow($values, $platforms);

            if ($reason !== null) {
                $summary['rejected']++;
                $issues[] = $this->issue($rowNumber, $reason);
                $details[] = $this->detail(
                    $rowNumber,
                    $values,
                    'invalid',
                    $reason,
                );

                continue;
            }

            $platformSlug = strtolower((string) $values['platform']);
            $platform = $platforms->get($platformSlug);
            $normalizedUrl = $this->normalizeUrl(
                (string) $values['product_url'],
            );
            $urlKey = $platformSlug.'|'.$normalizedUrl;
            $externalId = trim((string) $values['external_listing_id']);
            $externalId = $externalId === '' ? null : $externalId;
            $existingUrl = Listing::query()
                ->where('platform_id', $platform->getKey())
                ->where('product_url', $normalizedUrl)
                ->first(['external_listing_id']);

            if (isset($seenUrls[$urlKey])
                || ($existingUrl !== null
                    && $existingUrl->external_listing_id !== null
                    && $externalId !== null
                    && $existingUrl->external_listing_id !== $externalId)) {
                $summary['duplicate']++;
                $issues[] = $this->issue($rowNumber, 'duplicate URL');
                $details[] = $this->detail(
                    $rowNumber,
                    $values,
                    'duplicate',
                    'duplicate URL',
                );

                continue;
            }

            $seenUrls[$urlKey] = true;
            $values['platform'] = $platformSlug;
            $values['product_url'] = $normalizedUrl;
            $payload = $this->payload($values, $sourceName, $rowNumber);
            $listing = MarketplaceListingData::fromArray(
                $platformSlug,
                $payload,
            );

            if (! $listing instanceof MarketplaceListingData) {
                $summary['rejected']++;
                $issues[] = $this->issue($rowNumber, 'record tidak valid');
                $details[] = $this->detail(
                    $rowNumber,
                    $values,
                    'invalid',
                    'record tidak valid',
                );

                continue;
            }

            $matchedProduct = $this->products->match($listing);
            $matched = $matchedProduct !== null;
            $summary['valid']++;
            $summary[$matched ? 'matched' : 'unmatched']++;
            $details[] = $this->detail(
                $rowNumber,
                $values,
                'valid',
                null,
                $matchedProduct?->name,
            );
            $validRows[] = [
                'row_number' => $rowNumber,
                'platform_id' => $platform->getKey(),
                'platform_slug' => $platformSlug,
                'values' => $values,
                'source_name' => basename($sourceName),
                'matched' => $matched,
            ];
        }

        return [
            'summary' => $summary,
            'issues' => array_slice($issues, 0, 50),
            'details' => array_slice($details, 0, 50),
            'rows' => $validRows,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{counts:array<string,int>,issues:list<string>}
     */
    public function importRows(array $rows): array
    {
        $counts = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'matched' => 0,
            'unmatched' => 0,
        ];
        $issues = [];

        foreach ($rows as $row) {
            $rowNumber = max(1, (int) ($row['row_number'] ?? 1));
            $platform = Platform::query()->find($row['platform_id'] ?? null);
            $values = $row['values'] ?? null;

            if (! $platform instanceof Platform
                || ! is_array($values)
                || ! in_array($platform->slug, Platform::MVP_SLUGS, true)) {
                $counts['failed']++;
                $issues[] = $this->issue($rowNumber, 'platform tidak tersedia');

                continue;
            }

            $payload = $this->payload(
                $values,
                (string) ($row['source_name'] ?? 'marketplace-listings.csv'),
                $rowNumber,
            );
            $listing = MarketplaceListingData::fromArray(
                $platform->slug,
                $payload,
            );

            if (! $listing instanceof MarketplaceListingData) {
                $counts['failed']++;
                $issues[] = $this->issue($rowNumber, 'record tidak valid');

                continue;
            }

            try {
                $matched = $this->products->match($listing) !== null;
                $outcome = $this->ingestion->ingest(
                    $platform,
                    $listing,
                    'manual-import',
                );
                $counts[$outcome]++;
                $counts[$matched ? 'matched' : 'unmatched']++;
            } catch (Throwable) {
                $counts['failed']++;
                $issues[] = $this->issue($rowNumber, 'gagal diproses');
            }
        }

        return ['counts' => $counts, 'issues' => $issues];
    }

    /** @param list<mixed> $header */
    public function validHeader(array $header): bool
    {
        $normalized = array_map(static function (mixed $value, int $index): string {
            $value = is_string($value) ? strtolower(trim($value)) : '';

            return $index === 0
                ? preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value
                : $value;
        }, $header, array_keys($header));

        return $normalized === self::HEADERS;
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  Collection<string, Platform>  $platforms
     */
    private function validateRow(array $values, Collection $platforms): ?string
    {
        $slug = strtolower((string) ($values['platform'] ?? ''));

        if ($slug === '') {
            return 'platform wajib diisi';
        }

        if (! array_key_exists($slug, self::PLATFORM_HOSTS)) {
            return 'platform tidak didukung';
        }

        if (! $platforms->has($slug)) {
            return 'unknown platform';
        }

        if (trim((string) ($values['title'] ?? '')) === '') {
            return 'title wajib diisi';
        }

        $price = (string) ($values['price'] ?? '');

        if (! ctype_digit($price)
            || (int) $price < 1
            || (int) $price > Listing::MAX_MONEY) {
            return 'invalid price';
        }

        $currency = strtoupper(trim((string) ($values['currency'] ?? '')));

        if ($currency !== '' && $currency !== 'IDR') {
            return 'currency harus IDR';
        }

        $url = (string) ($values['product_url'] ?? '');
        if ($this->platformSlugForListingUrl($url) !== $slug) {
            return 'invalid URL';
        }

        return null;
    }

    public function platformSlugForListingUrl(string $url): ?string
    {
        $slug = $this->platformSlugForUrlHost($url);

        if ($slug === null) {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return $this->validListingPath($slug, $path) ? $slug : null;
    }

    public function platformSlugForUrlHost(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_PORT) !== null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::PLATFORM_HOSTS as $slug => $hosts) {
            if (in_array($host, $hosts, true)) {
                return $slug;
            }
        }

        return null;
    }

    private function validListingPath(string $slug, string $path): bool
    {
        return match ($slug) {
            'facebook' => preg_match(
                '~^/marketplace/item/\d{5,}/?$~',
                $path,
            ) === 1,
            'carousell' => preg_match(
                '#^/p/[A-Za-z0-9][A-Za-z0-9._~-]*-\d{5,}/?$#',
                $path,
            ) === 1,
            default => $path !== '' && $path !== '/',
        };
    }

    public function normalizeUrl(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($scheme === '' || $host === '' || $path === '') {
            return null;
        }

        $path = '/'.ltrim($path, '/');

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$path;
    }

    private function emptyRow(mixed $row): bool
    {
        return $row === false
            || $row === null
            || $row === [null]
            || (is_array($row) && collect($row)->every(
                static fn (mixed $value): bool => trim((string) $value) === '',
            ));
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    private function payload(
        array $values,
        string $filename,
        int $rowNumber,
    ): array {
        $payload = $values;

        foreach ($payload as $key => $value) {
            $payload[$key] = $value === '' ? null : $value;
        }

        $payload['currency'] ??= 'IDR';
        $payload['condition'] ??= 'unknown';
        $payload['seller_type'] ??= 'unknown';
        $payload['stock_status'] ??= 'unknown';
        $payload['price_type'] ??= 'full_price';
        $payload['import_source'] = 'csv';
        $payload['import_file'] = basename($filename);
        $payload['import_row'] = $rowNumber;

        return $payload;
    }

    private function issue(int $rowNumber, string $reason): string
    {
        return "Row {$rowNumber}: {$reason}";
    }

    /**
     * @param  array<string, string|null>|null  $values
     * @return array{row_number:int,platform:?string,title:?string,product_mapping:?string,status:string,reason:?string}
     */
    private function detail(
        int $rowNumber,
        ?array $values,
        string $status,
        ?string $reason,
        ?string $productMapping = null,
    ): array {
        $platform = trim((string) ($values['platform'] ?? ''));
        $title = trim((string) ($values['title'] ?? ''));

        return [
            'row_number' => $rowNumber,
            'platform' => $platform !== '' ? strtolower($platform) : null,
            'title' => $title !== '' ? $title : null,
            'product_mapping' => $productMapping,
            'status' => $status,
            'reason' => $reason,
        ];
    }
}
