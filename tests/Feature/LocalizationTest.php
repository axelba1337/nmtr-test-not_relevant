<?php

namespace Tests\Feature;

use App\Contracts\AI\ChatClient;
use App\Data\AI\ChatExplanationRequest;
use App\Data\AI\ParsedIntent;
use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('id');

        parent::tearDown();
    }

    public function test_default_locale_is_indonesian_and_user_header_has_switch(): void
    {
        $this->get(route('chatbot'))
            ->assertOk()
            ->assertSee('<html lang="id">', false)
            ->assertSee('Siap membantu')
            ->assertSee(route('locale.update', 'id'))
            ->assertSee(route('locale.update', 'en'))
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_locale_can_switch_both_ways_and_persists_in_session(): void
    {
        $this->from(route('chatbot'))
            ->post(route('locale.update', 'en'))
            ->assertRedirect(route('chatbot'))
            ->assertSessionHas('locale', 'en')
            ->assertCookie('neomatra_locale', 'en');

        $this->get(route('chatbot'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Ready to help');

        $this->from(route('chatbot'))
            ->post(route('locale.update', 'id'))
            ->assertRedirect(route('chatbot'))
            ->assertSessionHas('locale', 'id');

        $this->get(route('chatbot'))
            ->assertOk()
            ->assertSee('<html lang="id">', false)
            ->assertSee('Siap membantu');
    }

    public function test_invalid_locale_falls_back_safely_to_indonesian(): void
    {
        $this->withSession(['locale' => 'en'])
            ->from(route('chatbot'))
            ->post(route('locale.update', 'invalid-locale'))
            ->assertRedirect(route('chatbot'))
            ->assertSessionHas('locale', 'id');

        $this->withHeader('X-Neomatra-Locale', 'invalid')
            ->getJson(route('chatbot.suggestions'))
            ->assertOk();

        $this->assertSame('id', app()->getLocale());
    }

    public function test_locale_cookie_is_used_when_session_has_no_preference(): void
    {
        $this->withCookie('neomatra_locale', 'en')
            ->get(route('chatbot'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Ready to help');
    }

    public function test_locale_switch_preserves_the_current_page(): void
    {
        $currentPage = route('chatbot').'?source=locale-test';

        $this->from($currentPage)
            ->post(route('locale.update', 'en'))
            ->assertRedirect($currentPage);
    }

    public function test_ajax_locale_controls_deterministic_chatbot_messages(): void
    {
        Http::fake();

        $this->postJson(route('chatbot.query'), [
            'message' => '!!!',
            'locale' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('state', 'INVALID_INPUT')
            ->assertJsonPath(
                'answer',
                'The input could not be processed. Enter a product name or a clear question containing letters or numbers.',
            );

        Http::assertNothingSent();
    }

    public function test_bilingual_suggested_question_follows_selected_locale(): void
    {
        KnowledgeItem::query()->create([
            'title' => 'Apa itu Neomatra? / What is Neomatra?',
            'content' => 'ID: Penjelasan. EN: Explanation.',
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        $this->withHeader('X-Neomatra-Locale', 'en')
            ->getJson(route('chatbot.suggestions'))
            ->assertOk()
            ->assertJsonPath('suggestions.0.question', 'What is Neomatra?');

        $this->withHeader('X-Neomatra-Locale', 'id')
            ->getJson(route('chatbot.suggestions'))
            ->assertOk()
            ->assertJsonPath('suggestions.0.question', 'Apa itu Neomatra?');
    }

    public function test_invalid_ajax_locale_overrides_an_old_preference_with_safe_default(): void
    {
        Http::fake();

        $response = $this->withSession(['locale' => 'en'])
            ->withHeader('X-Neomatra-Locale', 'invalid')
            ->postJson(route('chatbot.query'), [
                'message' => '!!!',
                'locale' => 'invalid',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', 'INVALID_INPUT')
            ->assertJsonPath(
                'answer',
                'Input belum dapat diproses. Tulis nama produk atau pertanyaan dengan huruf atau angka yang jelas.',
            );

        $this->assertSame('id', app()->getLocale());
        Http::assertNothingSent();
    }

    public function test_admin_header_has_persistent_language_switch(): void
    {
        $admin = User::query()->create([
            'name' => 'Locale Admin',
            'email' => 'locale-admin@example.test',
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('Signed in as')
            ->assertSee(route('locale.update', 'id'))
            ->assertSee(route('locale.update', 'en'));
    }

    public function test_english_admin_pages_resolve_all_translation_keys(): void
    {
        $admin = User::query()->create([
            'name' => 'Translation Admin',
            'email' => 'translation-admin@example.test',
            'password' => Hash::make('password'),
            'is_admin' => true,
        ]);

        foreach ([
            'admin.dashboard',
            'admin.categories.index',
            'admin.products.index',
            'admin.platforms.index',
            'admin.listings.index',
            'admin.knowledge.index',
            'admin.ranking.edit',
            'admin.unanswered.index',
        ] as $routeName) {
            $this->actingAs($admin)
                ->withSession(['locale' => 'en'])
                ->get(route($routeName))
                ->assertOk()
                ->assertDontSee('ui.admin_pages.', false);
        }
    }

    public function test_indonesian_and_english_translation_catalogs_have_matching_keys(): void
    {
        $indonesian = $this->flattenTranslations(require lang_path('id/ui.php'));
        $english = $this->flattenTranslations(require lang_path('en/ui.php'));

        $this->assertSame(array_keys($indonesian), array_keys($english));
    }

    #[DataProvider('languageInstructions')]
    public function test_chatbot_uses_strict_selected_locale_instruction(
        string $locale,
        string $instruction,
        string $forbiddenInstruction,
    ): void {
        app()->setLocale($locale);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'answer' => 'RTX 3060',
                            'supported_by_context' => true,
                        ], JSON_THROW_ON_ERROR),
                    ]]],
                    'finishReason' => 'STOP',
                ]],
            ]),
        ]);

        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060',
            'intent_type' => ParsedIntent::TYPE_PRODUCT_SEARCH,
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => null,
            'sku_or_part_number' => null,
            'budget_min' => null,
            'budget_max' => null,
            'conditions' => [],
            'locations' => [],
            'seller_types' => [],
            'platforms' => [],
            'mode' => 'buyer',
            'needs_clarification' => false,
            'clarification_question' => null,
        ]);
        $this->assertNotNull($intent);

        app(ChatClient::class)->explain(new ChatExplanationRequest(
            'Find RTX 3060',
            $intent,
            [],
        ));

        Http::assertSent(function ($request) use (
            $instruction,
            $forbiddenInstruction,
        ): bool {
            $system = (string) data_get(
                $request->data(),
                'systemInstruction.parts.0.text',
            );
            $prompt = (string) data_get(
                $request->data(),
                'contents.0.parts.0.text',
            );

            return str_contains($system, $instruction)
                && ! str_contains($system, $forbiddenInstruction)
                && str_contains($prompt, 'RTX 3060');
        });
    }

    public function test_product_brand_and_model_names_are_not_translated(): void
    {
        $category = Category::query()->create([
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'NVIDIA GeForce RTX 3060',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB GDDR6',
            'normalized_key' => Product::makeNormalizedKey(
                'NVIDIA GeForce RTX 3060',
                'NVIDIA',
                'RTX 3060',
                '12 GB GDDR6',
            ),
            'is_active' => true,
        ]);

        $this->withSession(['locale' => 'en'])
            ->get(route('products.compare', $product))
            ->assertOk()
            ->assertSee('NVIDIA GeForce RTX 3060')
            ->assertSee('NVIDIA · RTX 3060 · 12 GB GDDR6');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function languageInstructions(): iterable
    {
        yield 'Indonesian' => [
            'id',
            'Jawab seluruhnya dalam Bahasa Indonesia.',
            'Respond entirely in English.',
        ];
        yield 'English' => [
            'en',
            'Respond entirely in English.',
            'Jawab seluruhnya dalam Bahasa Indonesia.',
        ];
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, string>
     */
    private function flattenTranslations(
        array $translations,
        string $prefix = '',
    ): array {
        $flattened = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flattened += $this->flattenTranslations($value, $path);
            } else {
                $flattened[$path] = (string) $value;
            }
        }

        ksort($flattened);

        return $flattened;
    }
}
