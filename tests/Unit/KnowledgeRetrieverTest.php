<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KnowledgeRetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.embedding.dimensions' => 2,
            'ai.embedding.model' => 'test-embedding-model',
            'neomatra.search.knowledge_top_k' => 3,
        ]);
    }

    public function test_it_orders_by_cosine_and_uses_id_for_equal_scores(): void
    {
        $first = $this->createCurrentItem('First', [1, 0]);
        $second = $this->createCurrentItem('Second', [1, 0]);
        $third = $this->createCurrentItem('Third', [0, 1]);

        $matches = app(KnowledgeRetriever::class)->retrieve([1, 0]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            array_column($matches, 'id'),
        );
        $this->assertSame([1.0, 1.0, 0.0], array_column($matches, 'similarity'));
    }

    public function test_it_keeps_zero_and_negative_scores_as_candidates(): void
    {
        $positive = $this->createCurrentItem('Positive', [1, 0]);
        $zero = $this->createCurrentItem('Zero', [0, 1]);
        $negative = $this->createCurrentItem('Negative', [-1, 0]);

        $matches = app(KnowledgeRetriever::class)->retrieve([1, 0]);

        $this->assertSame(
            [$positive->id, $zero->id, $negative->id],
            array_column($matches, 'id'),
        );
        $this->assertSame([1.0, 0.0, -1.0], array_column($matches, 'similarity'));
    }

    public function test_configured_top_k_is_clamped_and_applied(): void
    {
        config(['neomatra.search.knowledge_top_k' => 2]);

        $this->createCurrentItem('First', [1, 0]);
        $this->createCurrentItem('Second', [0.8, 0.2]);
        $this->createCurrentItem('Third', [0, 1]);

        $this->assertCount(
            2,
            app(KnowledgeRetriever::class)->retrieve([1, 0]),
        );

        config(['neomatra.search.knowledge_top_k' => 99]);

        for ($index = 4; $index <= 12; $index++) {
            $this->createCurrentItem("Item {$index}", [1, $index]);
        }

        $this->assertCount(
            10,
            app(KnowledgeRetriever::class)->retrieve([1, 0]),
        );
    }

    public function test_it_ignores_inactive_stale_and_invalid_rows(): void
    {
        $valid = $this->createCurrentItem('Valid', [1, 0]);
        $this->createCurrentItem('Inactive', [1, 0], false);

        KnowledgeItem::query()->create([
            'title' => 'Stale',
            'content' => 'Stale content',
            'embedding' => [1, 0],
            'embedding_hash' => str_repeat('a', 64),
            'embedding_model' => 'test-embedding-model',
            'embedded_at' => now(),
            'is_active' => true,
        ]);

        $wrongDimension = KnowledgeItem::query()->create([
            'title' => 'Wrong dimension',
            'content' => 'Wrong dimension content',
            'embedding' => [1, 0, 0],
            'embedding_model' => 'test-embedding-model',
            'embedded_at' => now(),
            'is_active' => true,
        ]);
        $wrongDimension->update([
            'embedding_hash' => $wrongDimension->expectedEmbeddingHash(),
        ]);

        $zeroVector = KnowledgeItem::query()->create([
            'title' => 'Zero vector',
            'content' => 'Zero vector content',
            'embedding' => [0, 0],
            'embedding_model' => 'test-embedding-model',
            'embedded_at' => now(),
            'is_active' => true,
        ]);
        $zeroVector->update([
            'embedding_hash' => $zeroVector->expectedEmbeddingHash(),
        ]);

        DB::table('knowledge_items')->insert([
            'title' => 'Malformed',
            'content' => 'Malformed content',
            'embedding' => '{"not":"a vector"}',
            'embedding_hash' => KnowledgeItem::makeEmbeddingHash(
                'test-embedding-model',
                2,
                'Malformed',
                'Malformed content',
            ),
            'embedding_model' => 'test-embedding-model',
            'embedded_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matches = app(KnowledgeRetriever::class)->retrieve([1, 0]);

        $this->assertSame([$valid->id], array_column($matches, 'id'));
    }

    public function test_it_rejects_an_invalid_query_vector(): void
    {
        $this->createCurrentItem('Valid', [1, 0]);

        $retriever = app(KnowledgeRetriever::class);

        $this->assertSame([], $retriever->retrieve([1, 0, 0]));
        $this->assertSame([], $retriever->retrieve([0, 0]));
        $this->assertSame([], $retriever->retrieve(['1', 0]));
    }

    public function test_it_validates_embeddings_with_all_semantic_metadata(): void
    {
        $category = Category::query()->create([
            'name' => 'Panduan',
            'slug' => 'panduan',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $item = KnowledgeItem::query()->create([
            'title' => 'Periksa kondisi barang',
            'content' => 'Validasi deskripsi dan foto seller.',
            'category_id' => $category->id,
            'keywords' => ['kondisi', 'seller'],
            'source_name' => 'SRS Neomatra',
            'embedding' => [1, 0],
            'is_active' => true,
        ]);
        $item->forceFill([
            'embedding_hash' => $item->expectedEmbeddingHash(),
            'embedding_model' => (string) config('ai.embedding.model'),
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
        ])->save();

        $matches = app(KnowledgeRetriever::class)->retrieve([1, 0]);

        $this->assertSame([$item->id], array_column($matches, 'id'));
    }

    private function createCurrentItem(
        string $title,
        array $embedding,
        bool $active = true,
    ): KnowledgeItem {
        $item = KnowledgeItem::query()->create([
            'title' => $title,
            'content' => "{$title} content",
            'embedding' => $embedding,
            'is_active' => $active,
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
