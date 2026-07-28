<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SuggestedQuestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_featured_questions_are_prioritized_and_limited_to_four(): void
    {
        $regularFirst = $this->createItem('Regular first', false, 0);
        $featuredSecond = $this->createItem('Featured second', true, 20);
        $featuredFirst = $this->createItem('Featured first', true, 10);
        $regularSecond = $this->createItem('Regular second', false, 30);
        $this->createItem('Regular fifth', false, 40);
        $this->createItem('Inactive featured', true, -100, false);

        $this->getJson('/api/chatbot/suggestions')
            ->assertOk()
            ->assertExactJson([
                'suggestions' => [
                    [
                        'id' => $featuredFirst->id,
                        'question' => 'Featured first',
                    ],
                    [
                        'id' => $featuredSecond->id,
                        'question' => 'Featured second',
                    ],
                    [
                        'id' => $regularFirst->id,
                        'question' => 'Regular first',
                    ],
                    [
                        'id' => $regularSecond->id,
                        'question' => 'Regular second',
                    ],
                ],
            ]);
    }

    public function test_first_active_questions_are_used_when_none_are_featured(): void
    {
        $later = $this->createItem('Later question', false, 20);
        $first = $this->createItem('First question', false, 10);
        $this->createItem('Inactive question', false, 0, false);

        $this->getJson('/api/chatbot/suggestions')
            ->assertOk()
            ->assertJsonCount(2, 'suggestions')
            ->assertJsonPath('suggestions.0.id', $first->id)
            ->assertJsonPath('suggestions.1.id', $later->id);
    }

    private function createItem(
        string $title,
        bool $featured,
        int $sortOrder,
        bool $active = true,
    ): KnowledgeItem {
        return KnowledgeItem::query()->create([
            'title' => $title,
            'content' => "{$title} answer",
            'is_active' => $active,
            'is_featured' => $featured,
            'sort_order' => $sortOrder,
        ]);
    }
}
