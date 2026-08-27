<?php

namespace App\Console\Commands;

use App\Data\Marketplace\MarketplaceListingData;
use App\Models\Listing;
use App\Models\Platform;
use App\Services\Marketplace\MarketplaceListingIngestionService;
use App\Services\Marketplace\MarketplaceProductMatcher;
use Illuminate\Console\Command;
use SplFileObject;
use Throwable;

final class ImportMarketplaceListings extends Command
{
    /** @var list<string> */
    private const HEADERS = [
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
    private const PLATFORM_HOSTS = [
        'olx' => ['olx.co.id', 'www.olx.co.id'],
        'tokopedia' => ['tokopedia.com', 'www.tokopedia.com'],
        'shopee' => ['shopee.co.id', 'www.shopee.co.id'],
        'facebook' => ['facebook.com', 'www.facebook.com'],
        'carousell' => ['id.carousell.com', 'carousell.com'],
    ];

    protected $signature = 'marketplace:import
                            {path : Path CSV relatif terhadap root project}';

    protected $description = 'Import real marketplace listings from a CSV file';

    public function __construct(
        private readonly MarketplaceListingIngestionService $ingestion,
        private readonly MarketplaceProductMatcher $products,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));

        if ($path === null) {
            $this->error('File CSV harus berada di dalam project dan dapat dibaca.');

            return self::INVALID;
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
            $this->error('Header CSV tidak sesuai format marketplace import.');

            return self::INVALID;
        }

        $platforms = Platform::query()
            ->whereIn('slug', array_keys(self::PLATFORM_HOSTS))
            ->get()
            ->keyBy('slug');
        $counts = [
            'seen' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'rejected' => 0,
            'failed' => 0,
            'matched' => 0,
            'unmatched' => 0,
        ];
        $issues = [];
        $seenUrls = [];

        while (! $file->eof()) {
            $row = $file->fgetcsv();
            $rowNumber = $file->key() + 1;

            if ($this->emptyRow($row)) {
                continue;
            }

            $counts['seen']++;

            if (! is_array($row) || count($row) !== count(self::HEADERS)) {
                $this->reject($counts, $issues, $rowNumber, 'jumlah kolom tidak valid');

                continue;
            }

            /** @var array<string, string|null> $values */
            $values = array_combine(self::HEADERS, array_map(
                static fn (mixed $value): ?string => is_string($value)
                    ? trim($value)
                    : null,
                $row,
            ));
            $reason = $this->validateRow($values, $platforms->all());

            if ($reason !== null) {
                $this->reject($counts, $issues, $rowNumber, $reason);

                continue;
            }

            $platformSlug = strtolower((string) $values['platform']);
            $platform = $platforms->get($platformSlug);
            $normalizedUrl = $this->normalizeUrl(
                (string) $values['product_url'],
            );

            if ($normalizedUrl === null) {
                $this->reject($counts, $issues, $rowNumber, 'invalid URL');

                continue;
            }

            $urlKey = $platformSlug.'|'.$normalizedUrl;

            if (isset($seenUrls[$urlKey])) {
                $this->reject($counts, $issues, $rowNumber, 'duplicate URL');

                continue;
            }

            $existingUrl = Listing::query()
                ->where('platform_id', $platform->getKey())
                ->where('product_url', $normalizedUrl)
                ->first(['external_listing_id']);
            $externalId = trim((string) $values['external_listing_id']);
            $externalId = $externalId === '' ? null : $externalId;

            if ($existingUrl !== null
                && $existingUrl->external_listing_id !== null
                && $externalId !== null
                && $existingUrl->external_listing_id !== $externalId) {
                $this->reject($counts, $issues, $rowNumber, 'duplicate URL');

                continue;
            }

            $seenUrls[$urlKey] = true;
            $values['product_url'] = $normalizedUrl;
            $payload = $this->payload(
                $values,
                basename($path),
                $rowNumber,
            );
            $listing = MarketplaceListingData::fromArray(
                $platformSlug,
                $payload,
            );

            if (! $listing instanceof MarketplaceListingData) {
                $this->reject($counts, $issues, $rowNumber, 'record tidak valid');

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
                $issues[] = "Row {$rowNumber}: gagal diproses";
            }
        }

        $this->table(['Metric', 'Total'], [
            ['Seen', $counts['seen']],
            ['Created', $counts['created']],
            ['Updated', $counts['updated']],
            ['Unchanged', $counts['unchanged']],
            ['Rejected', $counts['rejected']],
            ['Failed', $counts['failed']],
            ['Matched products', $counts['matched']],
            ['Unmatched products', $counts['unmatched']],
        ]);

        foreach ($issues as $issue) {
            $this->warn($issue);
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolvePath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return null;
        }

        $candidate = preg_match('~^(?:[a-z]:[\\\\/]|/)~i', $path) === 1
            ? $path
            : base_path($path);
        $resolved = realpath($candidate);
        $base = realpath(base_path());

        if (! is_string($resolved)
            || ! is_string($base)
            || ! is_file($resolved)
            || ! is_readable($resolved)) {
            return null;
        }

        $normalized = strtolower(str_replace('\\', '/', $resolved));
        $normalizedBase = rtrim(strtolower(str_replace('\\', '/', $base)), '/');

        return $normalized === $normalizedBase
            || str_starts_with($normalized, $normalizedBase.'/')
                ? $resolved
                : null;
    }

    /** @param list<mixed> $header */
    private function validHeader(array $header): bool
    {
        $normalized = array_map(static function (mixed $value, int $index): string {
            $value = is_string($value) ? strtolower(trim($value)) : '';

            return $index === 0
                ? preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value
                : $value;
        }, $header, array_keys($header));

        return $normalized === self::HEADERS;
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
     * @param  array<string, Platform>  $platforms
     */
    private function validateRow(array $values, array $platforms): ?string
    {
        $slug = strtolower((string) ($values['platform'] ?? ''));

        if ($slug === '') {
            return 'platform wajib diisi';
        }

        if (! array_key_exists($slug, self::PLATFORM_HOSTS)) {
            return 'platform tidak didukung';
        }

        if (! isset($platforms[$slug])) {
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
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_PORT) !== null
            || ! in_array($host, self::PLATFORM_HOSTS[$slug], true)
            || ! $this->validListingPath($slug, $path)) {
            return 'invalid URL';
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

    private function normalizeUrl(string $url): ?string
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
        $payload['import_file'] = $filename;
        $payload['import_row'] = $rowNumber;

        return $payload;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<string>  $issues
     */
    private function reject(
        array &$counts,
        array &$issues,
        int $rowNumber,
        string $reason,
    ): void {
        $counts['rejected']++;
        $issues[] = "Row {$rowNumber}: {$reason}";
    }
}
