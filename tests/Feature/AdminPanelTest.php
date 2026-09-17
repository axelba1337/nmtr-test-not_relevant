<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(
            '2026-07-28 12:00:00',
            'Asia/Jakarta',
        ));

        config([
            'ai.embedding.provider' => 'gemini',
            'ai.embedding.api_key' => 'admin-test-key',
            'ai.embedding.model' => 'admin-embedding-model',
            'ai.embedding.dimensions' => 2,
            'ai.http.retries' => 0,
        ]);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_cannot_open_or_mutate_admin_panel(): void
    {
        Http::fake();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->post(route('admin.knowledge.store'), $this->validPayload())
            ->assertRedirect(route('admin.login'));

        $this->post(route('admin.knowledge.rebuild'))
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('knowledge_items', 0);
        Http::assertNothingSent();
    }

    public function test_non_admin_user_is_forbidden_from_admin_panel(): void
    {
        Http::fake();
        $user = $this->createUser(isAdmin: false);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.knowledge.store'), $this->validPayload())
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('admin.knowledge.rebuild'))
            ->assertForbidden();

        $this->assertDatabaseCount('knowledge_items', 0);
        Http::assertNothingSent();
    }

    public function test_admin_login_and_layout_render_branding_and_favicons(): void
    {
        $this->withoutVite();
        $logo = asset('images/branding/neomatra-logo.png');

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('class="auth-brand__logo"', false)
            ->assertSee('data-brand-area="admin"', false)
            ->assertSee('data-brand-lock', false)
            ->assertSee('data-brand-mark', false)
            ->assertSee($logo)
            ->assertSee('rel="icon"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee(route('locale.update', 'id'))
            ->assertSee(route('locale.update', 'en'));

        $this->actingAs($this->createUser())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('class="admin-sidebar__logo"', false)
            ->assertSee('data-brand-area="admin"', false)
            ->assertSee('data-brand-lock', false)
            ->assertSee('data-brand-mark', false)
            ->assertSee($logo)
            ->assertSee('rel="icon"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee(route('admin.knowledge.index'))
            ->assertSee(route('admin.listings.index'));
    }

    public function test_listing_navigation_is_a_context_aware_dropdown(): void
    {
        $admin = $this->createUser();
        $destinations = [
            'listing-data' => route('admin.listings.index'),
            'automatic-listing' => route('admin.listings.discovery'),
            'import-csv' => route('admin.listings.import'),
        ];

        foreach ($destinations as $activeChild => $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();

            $response
                ->assertSee('data-admin-listing-group', false)
                ->assertSee('data-admin-listing-expanded="true"', false)
                ->assertSee('data-admin-nav-child="listing-data"', false)
                ->assertSee('data-admin-nav-child="automatic-listing"', false)
                ->assertSee('data-admin-nav-child="import-csv"', false)
                ->assertSee('data-admin-nav-child="'.$activeChild.'"', false)
                ->assertSee('data-admin-nav-active="true"', false)
                ->assertSee(route('admin.listings.index'))
                ->assertSee(route('admin.listings.discovery'))
                ->assertSee(route('admin.listings.import'));
            $this->assertMatchesRegularExpression(
                '/data-admin-nav-child="'.preg_quote($activeChild, '/').'"[^>]*data-admin-nav-active="true"/s',
                $response->getContent(),
            );
        }

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSeeText('Listing Data')
            ->assertSeeText('Automatic Listing')
            ->assertSeeText('Manual Listing (CSV Import)');

        $platform = Platform::query()->create([
            'name' => 'OLX',
            'slug' => 'olx',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'status' => Platform::STATUS_READY,
            'is_active' => true,
            'sync_enabled' => false,
        ]);
        $unmatched = Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'external_listing_id' => 'NAV-UNMATCHED-1',
            'source_fingerprint' => hash('sha256', 'nav-unmatched-1'),
            'title' => 'Unmatched navigation candidate',
            'price' => 1_000_000,
            'condition' => Listing::CONDITION_USED,
            'product_url' => 'https://www.olx.co.id/item/nav-unmatched-iid-990001',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->withSession(['locale' => 'id'])
            ->get(route('admin.listings.unmatched'))
            ->assertOk()
            ->assertSeeText('Tinjau Kandidat')
            ->assertSee(route('admin.listings.candidate.review', $unmatched))
            ->assertSee('data-admin-nav-child="listing-data"', false);
        Http::assertNothingSent();
    }

    public function test_only_admin_credentials_create_an_admin_session(): void
    {
        $admin = $this->createUser(
            isAdmin: true,
            email: 'admin@example.test',
        );
        $regularUser = $this->createUser(
            isAdmin: false,
            email: 'user@example.test',
        );

        $this->post(route('admin.login.store'), [
            'email' => "  {$regularUser->email}  ",
            'password' => 'test-password',
        ])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('admin.login.store'), [
            'email' => '  ADMIN@EXAMPLE.TEST  ',
            'password' => 'test-password',
        ])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_can_log_out_and_invalidate_the_session(): void
    {
        $admin = $this->createUser();

        $this->actingAs($admin)
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest();
    }

    public function test_dashboard_uses_today_and_seven_day_aggregates(): void
    {
        $admin = $this->createUser();
        $this->createLog(
            ChatbotQueryLog::STATUS_ANSWERED,
            1.0,
            now()->subHours(2),
        );
        $this->createLog(
            ChatbotQueryLog::STATUS_GENERATION_FAILED,
            0.0,
            now()->subHour(),
        );
        $this->createLog(
            ChatbotQueryLog::STATUS_EMBEDDING_FAILED,
            null,
            now(),
        );
        $this->createLog(
            ChatbotQueryLog::STATUS_ANSWERED,
            -1.0,
            now()->subDay(),
        );
        $this->createLog(
            ChatbotQueryLog::STATUS_ANSWERED,
            0.75,
            now()->subDays(8),
        );
        ChatbotQueryLog::query()->forceCreate([
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'query_text' => 'Belum memiliki hasil',
            'is_unanswered' => true,
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);

        $category = Category::query()->create([
            'name' => 'Produk Dashboard',
            'slug' => 'produk-dashboard',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Produk Aktif Dashboard',
            'is_active' => true,
        ]);
        $productWithoutListings = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Produk Tanpa Listing Dashboard',
            'is_active' => true,
        ]);
        Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Produk Nonaktif Dashboard',
            'is_active' => false,
        ]);
        $platform = Platform::query()->create([
            'name' => 'OLX',
            'slug' => 'olx',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'status' => Platform::STATUS_READY,
            'is_active' => true,
            'sync_enabled' => false,
        ]);
        Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'source_fingerprint' => str_repeat('b', 64),
            'title' => 'Listing Aktif Dashboard',
            'price' => 2500000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'product_url' => 'https://example.test/dashboard/active',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
        Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'source_fingerprint' => str_repeat('d', 64),
            'title' => 'Listing Belum Dipetakan Dashboard',
            'price' => 2250000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'product_url' => 'https://example.test/dashboard/unmatched',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
        Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'source_fingerprint' => str_repeat('c', 64),
            'title' => 'Listing Nonaktif Dashboard',
            'price' => 2000000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'product_url' => 'https://example.test/dashboard/inactive',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => false,
        ]);
        $this->createCurrentItem(['title' => 'Ready']);
        KnowledgeItem::query()->create([
            'title' => 'Missing',
            'content' => 'Missing answer',
            'is_active' => true,
        ]);
        KnowledgeItem::query()->create([
            'title' => 'Failed Dashboard Embedding',
            'content' => 'Failed answer',
            'embedding_error' => 'Embedding baru gagal dibuat.',
            'is_active' => true,
        ]);
        $this->createCurrentItem([
            'title' => 'Inactive',
            'is_active' => false,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('todayQuestions', 3)
            ->assertViewHas('activeListingsCount', 2)
            ->assertViewHas('activeProductsCount', 2)
            ->assertViewMissing('pendingUnansweredCount')
            ->assertViewHas('unmatchedListingsCount', 1)
            ->assertViewHas('productsWithoutListingsCount', 1)
            ->assertViewHas(
                'productsWithoutListings',
                static fn (mixed $products): bool => $products->pluck('id')
                    ->all() === [$productWithoutListings->id],
            )
            ->assertViewHas('activeKnowledgeCount', 3)
            ->assertViewMissing('averageTop1Similarity')
            ->assertViewHas('knowledgeStats', [
                'active' => 3,
                'ready' => 1,
                'failed' => 1,
                'pending' => 1,
                'warnings' => 0,
            ])
            ->assertViewHas(
                'activity',
                static function (array $activity): bool {
                    $byDate = collect($activity)->keyBy('date');

                    return count($activity) === 7
                        && $byDate['2026-07-27']['total'] === 1
                        && $byDate['2026-07-28']['total'] === 3;
                },
            )
            ->assertSee(e(route('admin.knowledge.index', [
                'status' => 'active',
                'embedding_status' => 'failed',
            ])), false)
            ->assertSee(e(route('admin.listings.unmatched')), false)
            ->assertDontSeeText('Rata-rata Similarity Top-1')
            ->assertDontSeeText('query belum ditinjau')
            ->assertSee('summary-grid--dashboard', false);

        $this->assertSame(
            3,
            substr_count($response->getContent(), '<article class="summary-card">'),
        );

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSeeText('Average Top-1 Similarity')
            ->assertDontSeeText('queries awaiting review');

        $this->actingAs($admin)
            ->get(route('admin.products.index', [
                'status' => 'active',
                'listing_status' => 'without_active',
            ]))
            ->assertOk()
            ->assertSeeText('Produk Tanpa Listing Dashboard')
            ->assertDontSeeText('Produk Aktif Dashboard');

        $this->actingAs($admin)
            ->get(route('admin.knowledge.index', [
                'status' => 'active',
                'embedding_status' => 'failed',
            ]))
            ->assertOk()
            ->assertSeeText('Failed Dashboard Embedding')
            ->assertDontSeeText('Ready');

        $this->actingAs($admin)
            ->get(route('admin.listings.index', [
                'status' => 'active',
                'mapping_status' => 'unmatched',
            ]))
            ->assertOk()
            ->assertSeeText('Listing Belum Dipetakan Dashboard')
            ->assertDontSeeText('Listing Aktif Dashboard');

    }

    public function test_admin_can_create_a_faq_and_its_embedding(): void
    {
        $admin = $this->createUser();
        $category = Category::query()->create([
            'name' => 'Panduan Produk',
            'slug' => 'panduan-produk',
            'is_active' => true,
        ]);
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [1, 0]],
            ]),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.knowledge.store'), [
                'title' => '  Pertanyaan baru?  ',
                'content' => '  Jawaban baru.  ',
                'category_id' => $category->id,
                'keywords' => "gpu, cek kondisi\nbekas",
                'source_name' => '  Dokumentasi  ',
                'source_url' => 'https://example.test/panduan',
                'is_active' => '1',
                'is_featured' => '1',
                'sort_order' => '4',
            ])
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $item = KnowledgeItem::query()->sole();

        $this->assertSame('Pertanyaan baru?', $item->title);
        $this->assertSame('Jawaban baru.', $item->content);
        $this->assertSame($category->id, $item->category_id);
        $this->assertSame(['gpu', 'cek kondisi', 'bekas'], $item->keywords);
        $this->assertSame('Dokumentasi', $item->source_name);
        $this->assertSame(
            'https://example.test/panduan',
            $item->source_url,
        );
        $this->assertTrue($item->is_active);
        $this->assertTrue($item->is_featured);
        $this->assertSame(4, $item->sort_order);
        $this->assertEquals([1.0, 0.0], $item->embedding);
        $this->assertSame('admin-embedding-model', $item->embedding_model);
        $this->assertNotNull($item->embedded_at);
        $this->assertNull($item->embedding_error);
        $this->assertTrue($item->hasCurrentEmbedding());

        Http::assertSentCount(1);
        Http::assertSent(
            static fn (Request $request): bool => data_get(
                $request->data(),
                'content.parts.0.text',
            ) === "Judul: Pertanyaan baru?\nKategori: Panduan Produk"
                ."\nKata kunci: bekas, cek kondisi, gpu"
                ."\nIsi: Jawaban baru.\nSumber: Dokumentasi",
        );
    }

    public function test_metadata_only_update_does_not_rebuild_embedding(): void
    {
        $admin = $this->createUser();
        $item = $this->createCurrentItem();
        $originalVector = $item->embedding;
        $originalHash = $item->embedding_hash;
        $originalModel = $item->embedding_model;
        $originalEmbeddedAt = $item->embedded_at?->toISOString();
        Http::fake();

        $this->actingAs($admin)
            ->put(
                route('admin.knowledge.update', $item),
                $this->validPayload([
                    'is_active' => false,
                    'is_featured' => true,
                    'sort_order' => 99,
                    'source_url' => 'https://example.test/metadata-only',
                ]),
            )
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertFalse($item->is_active);
        $this->assertTrue($item->is_featured);
        $this->assertSame(99, $item->sort_order);
        $this->assertSame(
            'https://example.test/metadata-only',
            $item->source_url,
        );
        $this->assertSame($originalVector, $item->embedding);
        $this->assertSame($originalHash, $item->embedding_hash);
        $this->assertSame($originalModel, $item->embedding_model);
        $this->assertSame(
            $originalEmbeddedAt,
            $item->embedded_at?->toISOString(),
        );
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('sourceChangeProvider')]
    public function test_question_or_answer_update_rebuilds_embedding(
        array $changes,
        string $expectedText,
    ): void {
        $admin = $this->createUser();
        $item = $this->createCurrentItem();
        $originalHash = $item->embedding_hash;
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [0, 1]],
            ]),
        ]);

        $this->actingAs($admin)
            ->put(
                route('admin.knowledge.update', $item),
                $this->validPayload($changes),
            )
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertEquals([0.0, 1.0], $item->embedding);
        $this->assertNotSame($originalHash, $item->embedding_hash);
        $this->assertSame(
            $item->expectedEmbeddingHash(),
            $item->embedding_hash,
        );
        $this->assertTrue($item->hasCurrentEmbedding());

        Http::assertSentCount(1);
        Http::assertSent(
            static fn (Request $request): bool => data_get(
                $request->data(),
                'content.parts.0.text',
            ) === $expectedText,
        );
    }

    public function test_category_change_rebuilds_knowledge_embedding(): void
    {
        $admin = $this->createUser();
        $item = $this->createCurrentItem();
        $category = Category::query()->create([
            'name' => 'PC dan Komponen',
            'slug' => 'pc-dan-komponen',
            'is_active' => true,
        ]);
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [0, 1]],
            ]),
        ]);

        $this->actingAs($admin)
            ->put(
                route('admin.knowledge.update', $item),
                $this->validPayload(['category_id' => $category->id]),
            )
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $item->refresh();
        $this->assertSame($category->id, $item->category_id);
        $this->assertTrue($item->hasCurrentEmbedding());
        Http::assertSentCount(1);
        Http::assertSent(
            static fn (Request $request): bool => data_get(
                $request->data(),
                'content.parts.0.text',
            ) === "Judul: Pertanyaan awal?\nKategori: PC dan Komponen"
                ."\nIsi: Jawaban awal.",
        );
    }

    public function test_failed_source_update_preserves_the_old_embedding(): void
    {
        $admin = $this->createUser();
        $item = $this->createCurrentItem();
        $originalVector = $item->embedding;
        $originalHash = $item->embedding_hash;
        $originalModel = $item->embedding_model;
        $originalEmbeddedAt = $item->embedded_at?->toISOString();
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'private upstream failure'],
            ], 503),
        ]);

        $this->actingAs($admin)
            ->put(
                route('admin.knowledge.update', $item),
                $this->validPayload([
                    'content' => 'Jawaban yang berubah.',
                ]),
            )
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('warning');

        $item->refresh();

        $this->assertSame('Jawaban yang berubah.', $item->content);
        $this->assertSame($originalVector, $item->embedding);
        $this->assertSame($originalHash, $item->embedding_hash);
        $this->assertSame($originalModel, $item->embedding_model);
        $this->assertSame(
            $originalEmbeddedAt,
            $item->embedded_at?->toISOString(),
        );
        $this->assertSame(
            'Embedding baru gagal dibuat.',
            $item->embedding_error,
        );
        $this->assertFalse($item->hasCurrentEmbedding());
        Http::assertSentCount(1);
    }

    public function test_admin_can_toggle_and_delete_a_faq(): void
    {
        $admin = $this->createUser();
        $item = $this->createCurrentItem([
            'is_featured' => false,
        ]);
        Http::fake();

        $this->actingAs($admin)
            ->from(route('admin.knowledge.index'))
            ->patch(route('admin.knowledge.toggle-active', $item))
            ->assertRedirect(route('admin.knowledge.index'));
        $this->assertFalse($item->fresh()?->is_active);

        $this->actingAs($admin)
            ->from(route('admin.knowledge.index'))
            ->patch(route('admin.knowledge.toggle-featured', $item))
            ->assertRedirect(route('admin.knowledge.index'));
        $this->assertTrue($item->fresh()?->is_featured);

        $this->actingAs($admin)
            ->delete(route('admin.knowledge.destroy', $item))
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_items', [
            'id' => $item->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_admin_can_rebuild_one_embedding(): void
    {
        $admin = $this->createUser();
        $item = $this->createCurrentItem();
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [0, 1]],
            ]),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.knowledge.index'))
            ->post(route('admin.knowledge.embedding.rebuild', $item))
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas('success');

        $item->refresh();

        $this->assertEquals([0.0, 1.0], $item->embedding);
        $this->assertTrue($item->hasCurrentEmbedding());
        Http::assertSentCount(1);
    }

    public function test_bulk_rebuild_processes_only_active_faqs(): void
    {
        $admin = $this->createUser();
        $first = $this->createCurrentItem(['title' => 'First']);
        $second = $this->createCurrentItem(['title' => 'Second']);
        $inactive = $this->createCurrentItem([
            'title' => 'Inactive',
            'is_active' => false,
        ]);
        $inactiveVector = $inactive->embedding;
        $inactiveEmbeddedAt = $inactive->embedded_at?->toISOString();

        Http::fake([
            '*' => Http::sequence()
                ->push(['embedding' => ['values' => [0, 1]]])
                ->push(['embedding' => ['values' => [-1, 0]]]),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.knowledge.index'))
            ->post(route('admin.knowledge.rebuild'))
            ->assertRedirect(route('admin.knowledge.index'))
            ->assertSessionHas(
                'success',
                'Rebuild selesai: 2 berhasil, 0 gagal, 0 dilewati.',
            );

        $this->assertEquals([0.0, 1.0], $first->fresh()?->embedding);
        $this->assertEquals([-1.0, 0.0], $second->fresh()?->embedding);
        $this->assertSame($inactiveVector, $inactive->fresh()?->embedding);
        $this->assertSame(
            $inactiveEmbeddedAt,
            $inactive->fresh()?->embedded_at?->toISOString(),
        );
        Http::assertSentCount(2);
    }

    public function test_bulk_rebuild_rejects_a_concurrent_request(): void
    {
        $admin = $this->createUser();
        $this->createCurrentItem();
        Http::fake();

        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->from(route('admin.knowledge.index'))
                ->post(route('admin.knowledge.rebuild'))
                ->assertRedirect(route('admin.knowledge.index'))
                ->assertSessionHas(
                    'warning',
                    'Proses rebuild knowledge lain masih berjalan.',
                );
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_stored_faq_content_is_escaped_in_admin_views(): void
    {
        $admin = $this->createUser();
        $title = '<script>alert("title")</script>';
        $content = '<img src=x onerror="alert(\'answer\')">';
        $item = KnowledgeItem::query()->create([
            'title' => $title,
            'content' => $content,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.knowledge.index'))
            ->assertOk()
            ->assertSee(e($title), false)
            ->assertSee(e($content), false)
            ->assertDontSee($title, false)
            ->assertDontSee($content, false);

        $this->actingAs($admin)
            ->get(route('admin.knowledge.edit', $item))
            ->assertOk()
            ->assertSee(e($title), false)
            ->assertSee(e($content), false)
            ->assertDontSee($title, false)
            ->assertDontSee($content, false);
    }

    private function createUser(
        bool $isAdmin = true,
        string $email = 'admin@example.test',
    ): User {
        return User::query()->create([
            'name' => $isAdmin ? 'Admin Test' : 'User Test',
            'email' => $email,
            'password' => 'test-password',
            'is_admin' => $isAdmin,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCurrentItem(array $overrides = []): KnowledgeItem
    {
        $item = KnowledgeItem::query()->create(array_merge([
            'title' => 'Pertanyaan awal?',
            'content' => 'Jawaban awal.',
            'embedding' => [1, 0],
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ], $overrides));

        $item->forceFill([
            'embedding_hash' => $item->expectedEmbeddingHash(),
            'embedding_model' => (string) config('ai.embedding.model'),
            'embedding_dimensions' => (int) config(
                'ai.embedding.dimensions',
            ),
            'embedded_at' => now()->subMinute(),
            'embedding_error' => null,
        ])->save();

        return $item->fresh();
    }

    private function createLog(
        string $status,
        ?float $similarity,
        Carbon $createdAt,
    ): ChatbotQueryLog {
        return ChatbotQueryLog::query()->forceCreate([
            'status' => $status,
            'top1_similarity' => $similarity,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Pertanyaan awal?',
            'content' => 'Jawaban awal.',
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
            'category_id' => null,
            'keywords' => '',
            'source_name' => '',
            'source_url' => '',
        ], $overrides);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function sourceChangeProvider(): array
    {
        return [
            'question changed' => [
                ['title' => 'Pertanyaan diperbarui?'],
                "Judul: Pertanyaan diperbarui?\nIsi: Jawaban awal.",
            ],
            'answer changed' => [
                ['content' => 'Jawaban diperbarui.'],
                "Judul: Pertanyaan awal?\nIsi: Jawaban diperbarui.",
            ],
            'keywords changed' => [
                ['keywords' => "semantic, gpu\nproduk"],
                "Judul: Pertanyaan awal?\nKata kunci: gpu, produk, semantic"
                    ."\nIsi: Jawaban awal.",
            ],
            'source name changed' => [
                ['source_name' => 'Dokumentasi Produk'],
                "Judul: Pertanyaan awal?\nIsi: Jawaban awal."
                    ."\nSumber: Dokumentasi Produk",
            ],
        ];
    }
}
