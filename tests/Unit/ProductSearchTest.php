<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Services\Search\ProductRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.embedding.model' => 'test-product-embedding',
            'ai.embedding.dimensions' => 2,
            'neomatra.search.product_candidate_k' => 20,
        ]);

        $this->category = Category::query()->create([
            'name' => 'GPU',
            'slug' => 'gpu-test',
            'is_active' => true,
        ]);
    }

    public function test_strict_attributes_keep_12gb_non_ti_desktop_variant(): void
    {
        $wanted = $this->createEmbeddedProduct(
            'NVIDIA GeForce RTX 3060',
            'RTX 3060',
            '12 GB',
            [0.80, 0.20],
        );
        $this->createEmbeddedProduct(
            'NVIDIA GeForce RTX 3060 Ti',
            'RTX 3060 Ti',
            '8 GB',
            [1.00, 0.00],
        );
        $this->createEmbeddedProduct(
            'NVIDIA GeForce RTX 3060',
            'RTX 3060',
            '8 GB',
            [0.99, 0.01],
        );
        $this->createEmbeddedProduct(
            'NVIDIA GeForce RTX 3060 Laptop GPU',
            'RTX 3060 Laptop',
            '12 GB',
            [0.98, 0.02],
        );

        $matches = app(ProductRetriever::class)->retrieve(
            [1.0, 0.0],
            'test-product-embedding',
            2,
            [
                'query' => 'RTX 3060 12GB bekas desktop',
                'brand' => 'NVIDIA',
                'model' => 'RTX 3060',
                'variant' => '12 GB',
                'sku_or_part_number' => null,
            ],
        );

        $this->assertCount(1, $matches);
        $this->assertSame($wanted->id, $matches[0]['product']->id);
    }

    public function test_cosine_candidates_remain_ordered_without_a_cutoff(): void
    {
        $positive = $this->createEmbeddedProduct(
            'Positive GPU',
            'Positive',
            null,
            [1.0, 0.0],
        );
        $zero = $this->createEmbeddedProduct(
            'Orthogonal GPU',
            'Orthogonal',
            null,
            [0.0, 1.0],
        );
        $negative = $this->createEmbeddedProduct(
            'Opposite GPU',
            'Opposite',
            null,
            [-1.0, 0.0],
        );

        $matches = app(ProductRetriever::class)->retrieve(
            [1.0, 0.0],
            'test-product-embedding',
            2,
            [
                'query' => 'gpu',
                'brand' => null,
                'model' => null,
                'variant' => null,
                'sku_or_part_number' => null,
            ],
        );

        $this->assertSame(
            [$positive->id, $zero->id, $negative->id],
            array_column(array_column($matches, 'product'), 'id'),
        );
        $this->assertSame(
            [1.0, 0.0, -1.0],
            array_column($matches, 'similarity'),
        );
    }

    public function test_category_gate_runs_before_the_candidate_limit(): void
    {
        config(['neomatra.search.product_candidate_k' => 1]);
        $cpu = Category::query()->create([
            'name' => 'CPU',
            'slug' => 'cpu-test',
            'is_active' => true,
        ]);
        $this->createEmbeddedProduct(
            'Intel Core i5',
            'Core i5',
            null,
            [1.0, 0.0],
            $cpu,
        );
        $wanted = $this->createEmbeddedProduct(
            'NVIDIA GPU',
            'GPU',
            null,
            [0.0, 1.0],
        );

        $matches = app(ProductRetriever::class)->retrieve(
            [1.0, 0.0],
            'test-product-embedding',
            2,
            [
                'query' => 'produk kategori GPU',
                'category' => 'GPU',
                'brand' => null,
                'model' => null,
                'variant' => null,
                'sku_or_part_number' => null,
            ],
        );

        $this->assertCount(1, $matches);
        $this->assertSame($wanted->id, $matches[0]['product']->id);
    }

    /** @param list<float> $embedding */
    private function createEmbeddedProduct(
        string $name,
        string $model,
        ?string $variant,
        array $embedding,
        ?Category $category = null,
    ): Product {
        $product = Product::query()->create([
            'category_id' => ($category ?? $this->category)->id,
            'name' => $name,
            'brand' => 'NVIDIA',
            'model' => $model,
            'variant' => $variant,
            'is_active' => true,
        ]);
        $product->forceFill([
            'embedding' => $embedding,
            'embedding_hash' => $product->expectedEmbeddingHash(
                'test-product-embedding',
                2,
            ),
            'embedding_model' => 'test-product-embedding',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
            'embedding_error' => null,
        ])->save();

        return $product;
    }
}
