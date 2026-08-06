<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ChatbotPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Neomatra']);
    }

    public function test_page_renders_the_accessible_neomatra_search_interface(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Neomatra')
            ->assertSee(
                'data-endpoint="'.route('chatbot.query').'"',
                false,
            )
            ->assertSee(
                'data-suggestions-endpoint="'
                    .route('chatbot.suggestions').'"',
                false,
            )
            ->assertSee('role="log"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('name="message"', false)
            ->assertSee('required', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('Pertanyaan yang disarankan')
            ->assertSee('Tanyakan apa saja')
            ->assertSee('<option value="">Otomatis</option>', false)
            ->assertSee('Transaksi tetap dilakukan di platform sumber')
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

    public function test_browser_code_uses_safe_text_rendering_and_safe_links(): void
    {
        $javascript = file_get_contents(resource_path('js/app.js'));
        $client = file_get_contents(resource_path('js/chatbot-client.js'));

        $this->assertIsString($javascript);
        $this->assertIsString($client);
        $this->assertStringContainsString('.textContent', $javascript);
        $this->assertStringContainsString(
            "['http:', 'https:']",
            $javascript.$client,
        );
        $this->assertStringContainsString(
            "source.rel = 'noopener noreferrer';",
            $javascript,
        );
        $this->assertStringContainsString(
            'title.textContent = source.title;',
            $javascript,
        );
        $this->assertStringContainsString(
            "submit.textContent = busy ? 'Mengirim…' : 'Kirim';",
            $javascript,
        );
        $this->assertStringContainsString(
            'history: [...conversationHistory]',
            $javascript,
        );
        $this->assertStringContainsString(
            'body: JSON.stringify(body)',
            $client,
        );
        $this->assertStringContainsString('normalizeSources', $client);
        $this->assertStringContainsString(
            'normalizeSearchEntryPointHtml',
            $client,
        );
        $this->assertStringContainsString(
            'iframe.srcdoc = html;',
            $javascript,
        );
        $this->assertStringContainsString(
            "iframe.title = 'Saran penelusuran Google terkait jawaban';",
            $javascript,
        );
        $this->assertStringContainsString(
            "'allow-popups allow-popups-to-escape-sandbox'",
            $javascript,
        );
        foreach (['allow-scripts', 'allow-forms', 'allow-same-origin'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $javascript);
        }
        $this->assertStringNotContainsString('.toFixed(6)', $javascript);
        $this->assertStringNotContainsString('meta.chat?.model', $javascript);
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
