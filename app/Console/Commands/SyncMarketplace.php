<?php

namespace App\Console\Commands;

use App\Data\Marketplace\MarketplaceSyncReport;
use App\Models\Platform;
use App\Services\Marketplace\MarketplaceSyncService;
use Illuminate\Console\Command;

final class SyncMarketplace extends Command
{
    protected $signature = 'marketplace:sync
                            {platform? : Slug platform yang akan disinkronkan}
                            {--pages= : Batas halaman per platform}';

    protected $description = 'Synchronize active marketplace listings';

    public function __construct(
        private readonly MarketplaceSyncService $sync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $platformSlug = $this->argument('platform');
        $pages = $this->option('pages');

        if ($pages !== null
            && (! ctype_digit((string) $pages) || (int) $pages < 1)) {
            $this->error('Option --pages harus berupa bilangan bulat positif.');

            return self::INVALID;
        }

        $query = Platform::query()->syncable()->orderBy('id');

        if (is_string($platformSlug) && trim($platformSlug) !== '') {
            $query->where('slug', strtolower(trim($platformSlug)));
        }

        $platforms = $query->get();

        if ($platforms->isEmpty()) {
            $this->error('Tidak ada platform aktif yang dapat disinkronkan.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($platforms as $platform) {
            $report = $this->sync->sync(
                $platform,
                $pages !== null ? (int) $pages : null,
            );
            $this->table(
                ['Platform', 'Status', 'Seen', 'Created', 'Updated', 'Unchanged', 'Skipped', 'Failed'],
                [[
                    $report->platform,
                    $report->status,
                    $report->seen,
                    $report->created,
                    $report->updated,
                    $report->unchanged,
                    $report->skipped,
                    $report->failed,
                ]],
            );

            if ($report->errorMessage !== null) {
                $this->warn($report->errorMessage);
            }

            $failed = $failed
                || $report->status !== MarketplaceSyncReport::STATUS_COMPLETED;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
