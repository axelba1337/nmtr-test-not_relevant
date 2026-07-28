<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
            'services.gemini.key' => 'command-test-key',
            'chatbot.models.embed' => 'command-embedding-model',
            'chatbot.embedding_dimensions' => 2,
            'chatbot.http.retry' => 0,
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
            "Pertanyaan: New item\nJawaban: New content",
            data_get($recorded[0][0]->data(), 'content.parts.0.text'),
        );
        $this->assertSame(
            "Pertanyaan: Changed item\nJawaban: Changed content",
            data_get($recorded[1][0]->data(), 'content.parts.0.text'),
        );
        $this->assertArrayNotHasKey(
            'title',
            data_get(
                $recorded[0][0]->data(),
                'embedContentConfig',
                [],
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
            'embedding_model' => (string) config('chatbot.models.embed'),
            'embedded_at' => now(),
        ]);

        return $item;
    }
}
