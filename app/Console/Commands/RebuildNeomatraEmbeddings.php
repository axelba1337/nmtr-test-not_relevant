<?php

namespace App\Console\Commands;

use App\Services\Embedding\EntityEmbeddingService;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class RebuildNeomatraEmbeddings extends Command
{
    protected $signature = 'neomatra:rebuild-embeddings
        {--force : Buat ulang vector yang masih valid}
        {--entity=all : all, products, atau knowledge}';

    protected $description = 'Membangun ulang embedding produk, alias, dan knowledge aktif.';

    public function handle(
        EntityEmbeddingService $entities,
        KnowledgeEmbeddingService $knowledge,
    ): int {
        $entity = strtolower((string) $this->option('entity'));

        if (! in_array($entity, ['all', 'products', 'knowledge'], true)) {
            $this->error('Opsi --entity harus all, products, atau knowledge.');

            return self::INVALID;
        }

        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->warn('Rebuild embedding lain sedang berjalan.');

            return self::FAILURE;
        }

        try {
            $force = (bool) $this->option('force');
            $failed = 0;

            if (in_array($entity, ['all', 'products'], true)) {
                $counts = $entities->rebuildActiveProducts($force);
                $failed += $counts['failed'];
                $this->line('Produk + alias: '.$this->summary($counts));
            }

            if (in_array($entity, ['all', 'knowledge'], true)) {
                $counts = $knowledge->rebuildActive($force);
                $failed += $counts['failed'];
                $this->line('Knowledge: '.$this->summary($counts));
            }
        } finally {
            $lock->release();
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array{processed: int, skipped: int, failed: int} $counts */
    private function summary(array $counts): string
    {
        return sprintf(
            '%d berhasil, %d dilewati, %d gagal',
            $counts['processed'],
            $counts['skipped'],
            $counts['failed'],
        );
    }
}
