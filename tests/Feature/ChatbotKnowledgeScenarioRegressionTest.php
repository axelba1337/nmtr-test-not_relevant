<?php

namespace Tests\Feature;

use App\Data\Search\SearchState;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use Database\Seeders\KnowledgeItemSeeder;
use Database\Seeders\NeomatraCatalogSeeder;
use Database\Seeders\NeomatraDatasetV2Seeder;
use Database\Seeders\NeomatraDatasetV3Seeder;
use Database\Seeders\NeomatraSearchVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ChatbotKnowledgeScenarioRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.chat.api_key' => 'knowledge-regression-chat-key',
            'ai.chat.model' => 'knowledge-regression-chat-model',
            'ai.chat.web_grounding.enabled' => false,
            'ai.embedding.api_key' => 'knowledge-regression-embedding-key',
            'ai.embedding.model' => 'knowledge-regression-embedding-model',
            'ai.embedding.dimensions' => 2,
            'ai.http.retries' => 0,
            'ai.http.retry_delay_ms' => 0,
            'chatbot.rate_limit' => 100,
        ]);

        $this->seed(NeomatraCatalogSeeder::class);
        $this->seed(NeomatraDatasetV2Seeder::class);
        $this->seed(KnowledgeItemSeeder::class);
        $this->seed(NeomatraDatasetV3Seeder::class);
        $this->seed(NeomatraSearchVocabularySeeder::class);
        Http::preventStrayRequests();
    }

    #[DataProvider('knowledgeScenarios')]
    public function test_knowledge_scenarios_are_grounded_in_an_active_item(
        string $query,
        string $title,
    ): void {
        $target = KnowledgeItem::query()
            ->active()
            ->where('title', $title)
            ->firstOrFail();
        $this->makeEmbeddingsCurrent($target);
        $answer = (string) $target->content;

        Http::fake([
            '*' => Http::sequence()
                ->push($this->intentResponse())
                ->push(['embedding' => ['values' => [1, 0]]])
                ->push($this->explanationResponse($answer)),
        ]);

        $this->postJson('/api/chatbot/query', ['message' => $query])
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('answer', $answer)
            ->assertJsonPath('matches.0.id', $target->id)
            ->assertJsonPath('listings', [])
            ->assertJsonPath('sources', [])
            ->assertJsonPath(
                'meta.mode',
                ChatbotQueryLog::MODE_KNOWLEDGE_ONLY,
            );
    }

    /** @return iterable<string, array{string, string}> */
    public static function knowledgeScenarios(): iterable
    {
        yield 'about Neomatra' => [
            'apa itu Neomatra?',
            'Tentang Neomatra / About Neomatra',
        ];
        yield 'about Neomatra in English' => [
            'what is Neomatra?',
            'Tentang Neomatra / About Neomatra',
        ];
        yield 'graphic card definition' => [
            'apa itu graphic card?',
            'Category vocabulary / Kosakata kategori',
        ];
        yield 'graphics card definition in English' => [
            'what is a graphics card?',
            'Category vocabulary / Kosakata kategori',
        ];
        yield 'Indonesian graphics card definition' => [
            'kartu grafis itu apa?',
            'Category vocabulary / Kosakata kategori',
        ];
        yield 'alternative selection policy' => [
            'bagaimana Neomatra mencari produk alternatif?',
            'Fallback produk terkait / Related product fallback',
        ];
        yield 'semantic search' => [
            'bagaimana semantic search bekerja?',
            'Semantic search',
        ];
        yield 'item condition vocabulary' => [
            'apa arti barang bekas/open box/refurbished?',
            'Open box, refurbished, dan ex-display',
        ];
        yield 'supported platforms' => [
            'platform apa yang didukung?',
            'Platform yang didukung / Supported platforms',
        ];
        yield 'price and condition disclaimer' => [
            'apakah harga/kondisi dijamin?',
            'Harga bukan jaminan / Prices are not guaranteed',
        ];
    }

    private function makeEmbeddingsCurrent(KnowledgeItem $target): void
    {
        $model = (string) config('ai.embedding.model');
        $dimensions = (int) config('ai.embedding.dimensions');

        KnowledgeItem::query()
            ->active()
            ->with('category')
            ->get()
            ->each(function (KnowledgeItem $item) use (
                $target,
                $model,
                $dimensions,
            ): void {
                $item->forceFill([
                    'embedding' => $item->is($target) ? [1, 0] : [0, 1],
                    'embedding_hash' => $item->expectedEmbeddingHash(
                        $model,
                        $dimensions,
                    ),
                    'embedding_model' => $model,
                    'embedding_dimensions' => $dimensions,
                    'embedded_at' => now(),
                    'embedding_error' => null,
                ])->save();
            });
    }

    /** @return array<string, mixed> */
    private function intentResponse(): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'product' => '',
                        'intent_type' => 'knowledge_question',
                        'category' => null,
                        'brand' => null,
                        'model' => null,
                        'variant' => null,
                        'sku_or_part_number' => null,
                        'budget_min' => null,
                        'budget_max' => null,
                        'conditions' => [],
                        'locations' => [],
                        'platforms' => [],
                        'needs_clarification' => false,
                        'clarification_question' => null,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]]],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function explanationResponse(string $answer): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'answer' => $answer,
                        'supported_by_context' => true,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]]],
                'finishReason' => 'STOP',
            ]],
        ];
    }
}
