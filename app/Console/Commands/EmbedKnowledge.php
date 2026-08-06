<?php

namespace App\Console\Commands;

use App\Services\KnowledgeEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EmbedKnowledge extends Command
{
    protected $signature = 'chatbot:embed-knowledge
                            {--force : Rebuild embeddings that are still current}';

    protected $description = 'Create embeddings for active knowledge items';

    public function __construct(
        private readonly KnowledgeEmbeddingService $embeddings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            $this->warn('Rebuild embedding lain sedang berjalan.');

            return self::FAILURE;
        }

        try {
            $counts = $this->embeddings->rebuildActive(
                (bool) $this->option('force'),
            );
        } finally {
            $lock->release();
        }

        $this->line(sprintf(
            'processed=%d skipped=%d failed=%d',
            $counts[KnowledgeEmbeddingService::STATUS_PROCESSED],
            $counts[KnowledgeEmbeddingService::STATUS_SKIPPED],
            $counts[KnowledgeEmbeddingService::STATUS_FAILED],
        ));

        return $counts[KnowledgeEmbeddingService::STATUS_FAILED] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
