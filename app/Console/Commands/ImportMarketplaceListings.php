<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceCsvImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ImportMarketplaceListings extends Command
{
    protected $signature = 'marketplace:import
                            {path : Path CSV relatif terhadap root project}';

    protected $description = 'Import real marketplace listings from a CSV file';

    public function __construct(
        private readonly MarketplaceCsvImportService $csv,
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

        try {
            $preview = $this->csv->previewFile($path, basename($path));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $result = $this->csv->importRows($preview['rows']);
        $summary = $preview['summary'];
        $counts = $result['counts'];
        $this->table(['Metric', 'Total'], [
            ['Seen', $summary['rows']],
            ['Created', $counts['created']],
            ['Updated', $counts['updated']],
            ['Unchanged', $counts['unchanged']],
            ['Rejected', $summary['rejected'] + $summary['duplicate']],
            ['Failed', $counts['failed']],
            ['Matched products', $counts['matched']],
            ['Unmatched products', $counts['unmatched']],
        ]);

        foreach ([...$preview['issues'], ...$result['issues']] as $issue) {
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
}
