<?php

namespace App\Console\Commands;

use App\Services\Marketplace\OlxMarketplaceConnector;
use Illuminate\Console\Command;

final class ProbeMarketplace extends Command
{
    protected $signature = 'marketplace:probe
                            {platform=olx : Slug platform yang diperiksa}';

    protected $description = 'Probe a marketplace connector without changing listing data';

    public function __construct(
        private readonly OlxMarketplaceConnector $olx,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $platform = strtolower(trim((string) $this->argument('platform')));

        if ($platform !== 'olx') {
            $this->error('Probe saat ini hanya tersedia untuk platform OLX.');

            return self::INVALID;
        }

        $probe = $this->olx->probe();
        $connected = is_int($probe['http_status'] ?? null);
        $rows = [
            ['Platform', (string) ($probe['platform'] ?? 'OLX')],
            ['URL', (string) ($probe['url'] ?? '-')],
            ['DNS/Connection', $connected ? 'OK' : 'FAILED'],
            ['HTTP status', $connected ? (string) $probe['http_status'] : '-'],
            ['Content-Type', (string) ($probe['content_type'] ?: '-')],
            ['Body size', number_format((int) ($probe['body_size'] ?? 0)).' bytes'],
            ['Redirect target', (string) ($probe['redirect_target'] ?: '-')],
            ['Final URL', (string) ($probe['final_url'] ?: '-')],
            ['Primary IP', (string) ($probe['primary_ip'] ?: '-')],
            ['HTTP version', (string) ($probe['http_version'] ?: '-')],
            ['Listing URLs', (string) ($probe['listing_urls'] ?? 0)],
            ['Parsed records', (string) ($probe['parsed_records'] ?? 0)],
            ['Rejected', (string) ($probe['rejected'] ?? 0)],
        ];

        if (filled($probe['reason'] ?? null)) {
            $rows[] = ['Reason', (string) $probe['reason']];
        }

        if (filled($probe['upstream_code'] ?? null)) {
            $rows[] = ['Technical code', (string) $probe['upstream_code']];
        }

        $this->table(['Check', 'Result'], $rows);

        return ($probe['failure_category'] ?? null) === null
            && (int) ($probe['parsed_records'] ?? 0) > 0
                ? self::SUCCESS
                : self::FAILURE;
    }
}
