<?php

namespace Tests\Feature;

use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\EmbeddingResult;
use App\Data\IntegrationFailure;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\User;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class AdminProductEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.embedding.model' => 'admin-product-model',
            'ai.embedding.dimensions' => 2,
        ]);
        $this->category = Category::query()->create([
            'name' => 'PC Components',
            'slug' => 'pc-dan-komponen',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_product_embedding_filters_use_the_exact_current_status(): void
    {
        $ready = $this->product('Ready Product');
        $this->makeCurrent($ready);
        $needsRebuild = $this->product('Needs Product');
        $needsRebuild->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => str_repeat('a', 64),
            'embedding_model' => 'old-model',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
        ])->saveQuietly();
        $failed = $this->product('Failed Product');
        $failed->forceFill([
            'embedding_error' => 'Embedding API configuration is incomplete.',
        ])->saveQuietly();
        $this->bindClient([], 0);
        $admin = $this->admin();

        foreach ([
            'ready' => [$ready->id],
            'needs_rebuild' => [$needsRebuild->id],
            'failed' => [$failed->id],
            'all' => [$failed->id, $needsRebuild->id, $ready->id],
            'invalid' => [$failed->id, $needsRebuild->id, $ready->id],
        ] as $status => $expectedIds) {
            $response = $this->actingAs($admin)
                ->get(route('admin.products.index', [
                    'embedding_status' => $status,
                ]))
                ->assertOk();

            $this->assertEqualsCanonicalizing(
                $expectedIds,
                $response->viewData('products')->getCollection()->pluck('id')->all(),
            );
        }

        $this->actingAs($admin)
            ->get(route('admin.products.index').'?embedding_status[]=ready')
            ->assertOk()
            ->assertSee('id="product-embedding-status"', false)
            ->assertSeeText('Rebuild dilakukan per produk agar proses lebih ringan')
            ->assertDontSeeText('Rebuild Semua Embedding yang Diperlukan')
            ->assertSee(route('admin.products.embedding.rebuild', $ready));

        $this->assertFalse(Route::has('admin.products.embedding.rebuild-required'));
    }

    public function test_embedding_sort_prioritizes_failed_then_needs_rebuild_then_ready(): void
    {
        $ready = $this->product('Alpha Ready');
        $this->makeCurrent($ready);
        $needsRebuild = $this->product('Zulu Needs Rebuild');
        $failed = $this->product('Middle Failed');
        $failed->forceFill(['embedding_error' => 'Quota reached.'])->saveQuietly();
        $this->bindClient([], 0);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.products.index', ['sort' => 'embedding']))
            ->assertOk();

        $this->assertSame(
            [$failed->id, $needsRebuild->id, $ready->id],
            $response->viewData('products')->getCollection()->pluck('id')->all(),
        );
    }

    public function test_rebuild_one_updates_stale_product_and_aliases_but_skips_current_alias(): void
    {
        $product = $this->product('Stale Product');
        $staleAlias = $this->alias($product, 'Stale Alias');
        $currentAlias = $this->alias($product, 'Current Alias');
        $this->makeCurrent($currentAlias);
        $currentAliasHash = $currentAlias->embedding_hash;
        $this->bindClient([
            EmbeddingResult::success([1.0, 0.0], 'admin-product-model', 2),
            EmbeddingResult::success([0.0, 1.0], 'admin-product-model', 2),
        ], 2);

        $this->actingAs($this->admin())
            ->post(route('admin.products.embedding.rebuild', $product))
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                'Embedding produk berhasil diperbarui.',
            );

        $this->assertTrue($this->current($product->fresh()));
        $this->assertTrue($this->current($staleAlias->fresh()));
        $this->assertSame($currentAliasHash, $currentAlias->fresh()?->embedding_hash);
    }

    public function test_rebuild_one_skips_a_fully_current_product_tree_without_provider_calls(): void
    {
        $product = $this->product('Current Product');
        $alias = $this->alias($product, 'Current Product Alias');
        $this->makeCurrent($product);
        $this->makeCurrent($alias);
        $this->bindClient([], 0);

        $this->actingAs($this->admin())
            ->post(route('admin.products.embedding.rebuild', $product))
            ->assertRedirect()
            ->assertSessionHas('success', 'Embedding produk sudah terbaru.');
    }

    public function test_rebuild_failure_preserves_vector_and_records_safe_real_condition(): void
    {
        $product = $this->product('Failed Rebuild Product');
        $product->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => str_repeat('b', 64),
            'embedding_model' => 'old-model',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
        ])->saveQuietly();
        $this->bindClient([
            EmbeddingResult::failed(
                'admin-product-model',
                2,
                new IntegrationFailure(
                    'gemini',
                    'embedding',
                    'quota_or_rate_limit',
                    'Batas penggunaan layanan AI sedang tercapai.',
                ),
            ),
        ], 1);

        $this->actingAs($this->admin())
            ->post(route('admin.products.embedding.rebuild', $product))
            ->assertRedirect()
            ->assertSessionHas(
                'warning',
                'Sebagian embedding gagal dibuat. Periksa konfigurasi atau koneksi Embedding API.',
            );

        $product->refresh();
        $this->assertSame([1, 0], $product->embedding);
        $this->assertSame(str_repeat('b', 64), $product->embedding_hash);
        $this->assertSame(
            'Batas penggunaan layanan AI sedang tercapai.',
            $product->embedding_error,
        );
    }

    public function test_product_embedding_actions_require_admin_and_share_the_global_lock(): void
    {
        $product = $this->product('Protected Product');
        $this->bindClient([], 0);

        $this->post(route('admin.products.embedding.rebuild', $product))
            ->assertRedirect(route('admin.login'));
        $this->actingAs($this->admin(false))
            ->post(route('admin.products.embedding.rebuild', $product))
            ->assertForbidden();

        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($this->admin())
                ->post(route('admin.products.embedding.rebuild', $product))
                ->assertRedirect()
                ->assertSessionHas(
                    'warning',
                    'Proses rebuild embedding lain masih berjalan.',
                );
        } finally {
            $lock->release();
        }
    }

    public function test_product_embedding_command_keeps_non_force_skip_semantics(): void
    {
        $current = $this->product('Current Command Product');
        $this->makeCurrent($current);
        $stale = $this->product('Stale Command Product');
        $this->bindClient([
            EmbeddingResult::success([1.0, 0.0], 'admin-product-model', 2),
        ], 1);

        $exitCode = Artisan::call('neomatra:rebuild-embeddings', [
            '--entity' => 'products',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'Produk + alias: 1 berhasil, 1 dilewati, 0 gagal',
            Artisan::output(),
        );
        $this->assertTrue($this->current($stale->fresh()));
    }

    /** @param list<EmbeddingResult> $results */
    private function bindClient(array $results, int $expectedCalls): void
    {
        $client = $this->createMock(EmbeddingClient::class);
        $client->method('model')->willReturn('admin-product-model');
        $client->method('dimensions')->willReturn(2);
        $client->method('provider')->willReturn('gemini');
        $expectation = $client->expects($this->exactly($expectedCalls))
            ->method('embed');

        if ($results !== []) {
            $expectation->willReturnOnConsecutiveCalls(...$results);
        }

        $this->app->instance(EmbeddingClient::class, $client);
    }

    private function admin(bool $isAdmin = true): User
    {
        return User::query()->create([
            'name' => $isAdmin ? 'Embedding Admin' : 'Regular User',
            'email' => uniqid('embedding-', true).'@example.test',
            'password' => 'password',
            'is_admin' => $isAdmin,
        ]);
    }

    private function product(string $name, bool $active = true): Product
    {
        return Product::query()->create([
            'category_id' => $this->category->id,
            'name' => $name,
            'is_active' => $active,
        ]);
    }

    private function alias(Product $product, string $alias): ProductAlias
    {
        return $product->aliases()->create(['alias' => $alias]);
    }

    private function makeCurrent(Product|ProductAlias $entity): void
    {
        $entity = $entity instanceof Product
            ? $entity->load('category')
            : $entity->load('product.category');
        $entity->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => $entity->expectedEmbeddingHash(
                'admin-product-model',
                2,
            ),
            'embedding_model' => 'admin-product-model',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
            'embedding_error' => null,
        ])->saveQuietly();
    }

    private function current(Product|ProductAlias|null $entity): bool
    {
        if ($entity === null) {
            return false;
        }

        $entity->loadMissing(
            $entity instanceof Product ? 'category' : 'product.category',
        );

        return $entity->hasCurrentEmbedding('admin-product-model', 2);
    }
}
