<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class EmbedKnowledgeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.embedding.provider' => 'gemini',
            'ai.embedding.api_key' => 'command-test-key',
            'ai.embedding.model' => 'command-embedding-model',
            'ai.embedding.dimensions' => 2,
            'ai.embedding.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.http.retries' => 0,
        ]);

        Http::preventStrayRequests();
    }

    public function test_command_embeds_new_and_changed_items(): void
    {
        $new = KnowledgeItem::query()->create([
            'title' => 'New item',
            'content' => 'New content',
            'is_active' => true,
        ]);
        $changed = $this->createCurrentItem(
            'Changed item',
            'Original content',
            [1, 0],
        );
        $changed->update(['content' => 'Changed content']);

        KnowledgeItem::query()->create([
            'title' => 'Inactive item',
            'content' => 'Inactive content',
            'is_active' => false,
        ]);

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'embedding' => ['values' => [1, 0]],
                ])
                ->push([
                    'embedding' => ['values' => [0, 1]],
                ]),
        ]);

        $exitCode = Artisan::call('chatbot:embed-knowledge');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'processed=2 skipped=0 failed=0',
            Artisan::output(),
        );

        $new->refresh();
        $changed->refresh();

        $this->assertEquals([1.0, 0.0], $new->embedding);
        $this->assertSame(
            $new->expectedEmbeddingHash(),
            $new->embedding_hash,
        );
        $this->assertEquals([0.0, 1.0], $changed->embedding);
        $this->assertSame(
            $changed->expectedEmbeddingHash(),
            $changed->embedding_hash,
        );
        $this->assertSame(
            'command-embedding-model',
            $new->embedding_model,
        );
        $this->assertNotNull($new->embedded_at);
        $this->assertNull($new->embedding_error);

        $recorded = Http::recorded();
        $this->assertSame(
            "Judul: New item\nIsi: New content",
            data_get($recorded[0][0]->data(), 'content.parts.0.text'),
        );
        $this->assertSame(
            "Judul: Changed item\nIsi: Changed content",
            data_get($recorded[1][0]->data(), 'content.parts.0.text'),
        );
        $this->assertSame(
            'New item',
            data_get(
                $recorded[0][0]->data(),
                'embedContentConfig.title',
            ),
        );
        Http::assertSentCount(2);
    }

    public function test_command_skips_an_unchanged_valid_item(): void
    {
        $item = $this->createCurrentItem(
            'Current item',
            'Current content',
            [1, 0],
        );
        $originalHash = $item->embedding_hash;

        Http::fake();

        $exitCode = Artisan::call('chatbot:embed-knowledge');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'processed=0 skipped=1 failed=0',
            Artisan::output(),
        );

        $item->refresh();

        $this->assertSame([1, 0], $item->embedding);
        $this->assertSame($originalHash, $item->embedding_hash);
        Http::assertNothingSent();
    }

    public function test_force_option_rebuilds_a_current_item(): void
    {
        $item = $this->createCurrentItem(
            'Current item',
            'Current content',
            [1, 0],
        );

        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [0, 1]],
            ]),
        ]);

        $exitCode = Artisan::call(
            'chatbot:embed-knowledge',
            ['--force' => true],
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'processed=1 skipped=0 failed=0',
            Artisan::output(),
        );

        $item->refresh();

        $this->assertEquals([0.0, 1.0], $item->embedding);
        $this->assertSame(
            $item->expectedEmbeddingHash(),
            $item->embedding_hash,
        );
        Http::assertSentCount(1);
    }

    public function test_partial_failure_preserves_the_previous_vector(): void
    {
        $first = $this->createCurrentItem(
            'First item',
            'First content',
            [1, 0],
        );
        $second = $this->createCurrentItem(
            'Second item',
            'Second content',
            [0, 1],
        );
        $secondVector = $second->embedding;
        $secondHash = $second->embedding_hash;

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'embedding' => ['values' => [0.5, 0.5]],
                ])
                ->push([], 503),
        ]);

        $exitCode = Artisan::call(
            'chatbot:embed-knowledge',
            ['--force' => true],
        );

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'processed=1 skipped=0 failed=1',
            Artisan::output(),
        );

        $first->refresh();
        $second->refresh();

        $this->assertSame([0.5, 0.5], $first->embedding);
        $this->assertSame($secondVector, $second->embedding);
        $this->assertSame($secondHash, $second->embedding_hash);
        $this->assertSame(
            'Embedding baru gagal dibuat.',
            $second->embedding_error,
        );
    }

    public function test_seeding_is_offline_and_idempotent(): void
    {
        Http::fake();

        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('knowledge_items', 3);
        $this->assertSame(
            3,
            KnowledgeItem::query()
                ->whereNull('embedding')
                ->whereNull('embedding_hash')
                ->whereNull('embedding_model')
                ->whereNull('embedded_at')
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_reseeding_preserves_existing_embedding_metadata(): void
    {
        Http::fake();
        $this->seed();
        $item = KnowledgeItem::query()->firstOrFail();
        $item->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => $item->expectedEmbeddingHash(),
            'embedding_model' => (string) config('ai.embedding.model'),
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
            'embedding_error' => null,
        ])->save();
        $originalHash = $item->embedding_hash;

        $this->seed();
        $item->refresh();

        $this->assertSame([1, 0], $item->embedding);
        $this->assertSame($originalHash, $item->embedding_hash);
        $this->assertSame(2, $item->embedding_dimensions);
        $this->assertNotNull($item->embedded_at);
        Http::assertNothingSent();
    }

    public function test_all_rebuild_entry_points_share_one_lock(): void
    {
        Http::fake();
        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $exitCode = Artisan::call('chatbot:embed-knowledge');
        } finally {
            $lock->release();
        }

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Rebuild embedding lain sedang berjalan.',
            Artisan::output(),
        );
        Http::assertNothingSent();
    }

    public function test_neomatra_rebuild_returns_failure_when_an_embedding_fails(): void
    {
        KnowledgeItem::query()->create([
            'title' => 'Knowledge gagal',
            'content' => 'Konten gagal.',
            'is_active' => true,
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        $exitCode = Artisan::call('neomatra:rebuild-embeddings', [
            '--entity' => 'knowledge',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Knowledge: 0 berhasil, 0 dilewati, 1 gagal',
            Artisan::output(),
        );
        Http::assertSentCount(1);
    }

    private function createCurrentItem(
        string $title,
        string $content,
        array $embedding,
    ): KnowledgeItem {
        $item = KnowledgeItem::query()->create([
            'title' => $title,
            'content' => $content,
            'embedding' => $embedding,
            'is_active' => true,
        ]);
        $item->update([
            'embedding_hash' => $item->expectedEmbeddingHash(),
            'embedding_model' => (string) config('ai.embedding.model'),
            'embedding_dimensions' => (int) config(
                'ai.embedding.dimensions',
            ),
            'embedded_at' => now(),
        ]);

        return $item;
    }
}
