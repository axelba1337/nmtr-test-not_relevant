<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ChatbotPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Semantic Chatbot Lite']);
    }

    public function test_page_renders_the_generic_accessible_chat_interface(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Semantic Chatbot Lite')
            ->assertSee('data-endpoint="http://localhost/api/chatbot/query"', false)
            ->assertSee('data-suggestions-endpoint="http://localhost/api/chatbot/suggestions"', false)
            ->assertSee('role="log"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('name="message"', false)
            ->assertSee('required', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('Pertanyaan yang disarankan')
            ->assertSee('Kirim');
    }

    public function test_page_source_declares_both_vite_entries(): void
    {
        $blade = file_get_contents(
            resource_path('views/chatbot/widget.blade.php'),
        );

        $this->assertIsString($blade);
        $this->assertStringContainsString(
            "@vite(['resources/css/app.css', 'resources/js/app.js'])",
            $blade,
        );
    }

    public function test_browser_code_uses_safe_text_rendering_and_signed_six_decimal_scores(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $client = file_get_contents(resource_path('js/chatbot-client.js'));

        $this->assertIsString($javascript);
        $this->assertIsString($client);
        $this->assertStringContainsString('.textContent', $javascript);
        $this->assertStringContainsString('.toFixed(6)', $javascript);
        $this->assertStringContainsString(
            "const sign = similarity >= 0 ? '+' : '';",
            $javascript,
        );
        $this->assertStringContainsString(
            'body: JSON.stringify({ message })',
            $client,
        );
        $this->assertStringNotContainsString(
            '.innerHTML',
            $javascript.$client,
        );
    }

    public function test_removed_web_routes_return_404(): void
    {
        foreach ([
            '/chatbot',
            '/chatbot/widget',
            '/chatbot/demo',
            '/admin/chatbot',
            '/admin/chatbot/faqs',
            '/admin/chatbot/unanswered',
        ] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_removed_api_routes_return_404(): void
    {
        foreach ([
            '/api/chatbot/ping',
            '/api/chatbot/dev/models',
            '/api/admin/chatbot/metrics',
            '/api/admin/chatbot/faqs',
            '/api/admin/chatbot/unanswered',
        ] as $path) {
            $this->getJson($path)->assertNotFound();
        }

        $this->postJson('/api/chatbot/events', [])
            ->assertNotFound();
    }
}
