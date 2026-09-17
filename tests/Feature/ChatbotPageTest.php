<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
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
            ->assertSee('class="chat-brand__logo"', false)
            ->assertSee('data-brand-area="user"', false)
            ->assertSee('data-brand-lock', false)
            ->assertSee('data-brand-mark', false)
            ->assertSee(asset('images/branding/neomatra-logo.png'))
            ->assertSee(asset('images/branding/neomatra-wordmark-bg.png'))
            ->assertSee('rel="icon"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('name="message"', false)
            ->assertSee('<textarea id="chatbot-message"', false)
            ->assertSee('rows="1"', false)
            ->assertDontSee('<input id="chatbot-message"', false)
            ->assertSee('required', false)
            ->assertSee('maxlength="2000"', false)
            ->assertSee('PERTANYAAN CEPAT')
            ->assertSee('Tulis kebutuhan Anda secara natural untuk mencari knowledge dan listing marketplace yang relevan.')
            ->assertSee('<strong>Cakupan:</strong>', false)
            ->assertSee('PC Components, Handphone, Laptop, PC Accessories, dan Networking.')
            ->assertSee('<strong>Sumber Listing:</strong>', false)
            ->assertSee('Tokopedia, Shopee, OLX, Facebook Marketplace, dan Carousell.')
            ->assertSee('<option value="">Semua kondisi</option>', false)
            ->assertSee('<option value="new">Baru</option>', false)
            ->assertSee('<option value="used">Bekas</option>', false)
            ->assertSee('<option value="unknown">Tidak diketahui</option>', false)
            ->assertDontSee('<option value="open_box">', false)
            ->assertDontSee('<option value="refurbished">', false)
            ->assertDontSee('<option value="ex_display">', false)
            ->assertDontSee('<option value="">Otomatis</option>', false)
            ->assertSee('Harga dan kondisi berasal dari listing sumber')
            ->assertSee('Kirim')
            ->assertSeeText('Tentang Neomatra')
            ->assertSeeText('Kontak Dukungan')
            ->assertSee('href="'.route('about').'"', false)
            ->assertSee('href="'.route('contact').'"', false)
            ->assertDontSee('href="'.route('admin.login').'"', false);
    }

    public function test_english_condition_filter_uses_only_the_simple_groups(): void
    {
        $this->withoutVite();

        $this->withSession(['locale' => 'en'])
            ->get('/')
            ->assertOk()
            ->assertSeeText('QUICK QUERY')
            ->assertSee('Describe what you need and Neomatra will search relevant knowledge and marketplace listings.')
            ->assertSee('<strong>Scope:</strong>', false)
            ->assertSee('PC Components, Mobile Phones, Laptops, PC Accessories, and Networking.')
            ->assertSee('<strong>Listing Sources:</strong>', false)
            ->assertSee('Tokopedia, Shopee, OLX, Facebook Marketplace, and Carousell.')
            ->assertSeeText('About Neomatra')
            ->assertSeeText('Contact Support')
            ->assertDontSee('PERTANYAAN CEPAT')
            ->assertDontSee('Tulis kebutuhan Anda secara natural')
            ->assertSee('<option value="">All conditions</option>', false)
            ->assertSee('<option value="new">New</option>', false)
            ->assertSee('<option value="used">Used</option>', false)
            ->assertSee('<option value="unknown">Unknown</option>', false)
            ->assertDontSee('<option value="open_box">', false)
            ->assertDontSee('<option value="refurbished">', false)
            ->assertDontSee('<option value="ex_display">', false);
    }

    public function test_public_startup_splash_is_localized_safe_and_excluded_from_admin(): void
    {
        $this->withoutVite();
        Http::fake();

        $this->withSession(['locale' => 'id'])
            ->get(route('chatbot'))
            ->assertOk()
            ->assertSee('data-startup-splash', false)
            ->assertSee('aria-hidden="true"', false)
            ->assertSee(asset('images/branding/neomatra-logo.png'))
            ->assertSeeText('PENCARIAN MARKETPLACE SEMANTIK')
            ->assertSeeText('MENYIAPKAN PENCARIAN SEMANTIK')
            ->assertSeeText('MEMUAT INTELIJENSI PRODUK')
            ->assertSeeText('MENYIAPKAN SUMBER LISTING')
            ->assertSeeText('SISTEM SIAP')
            ->assertSee('<textarea id="chatbot-message"', false);

        $this->withSession(['locale' => 'en'])
            ->get(route('chatbot'))
            ->assertOk()
            ->assertSeeText('SEMANTIC MARKET SEARCH')
            ->assertSeeText('INITIALIZING SEMANTIC SEARCH')
            ->assertSeeText('LOADING PRODUCT INTELLIGENCE')
            ->assertSeeText('SYNCING MARKETPLACE SOURCES')
            ->assertSeeText('SYSTEM READY')
            ->assertDontSeeText('MENYIAPKAN PENCARIAN SEMANTIK');

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee('data-startup-splash', false);

        Http::assertNothingSent();
    }

    public function test_startup_splash_css_has_a_failure_safe_timeout_and_reduced_motion(): void
    {
        $stylesheet = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($stylesheet);
        $this->assertMatchesRegularExpression(
            '/\.startup-splash\s*\{[^}]*pointer-events:\s*none;[^}]*startup-splash-fallback\s+1\.35s/s',
            $stylesheet,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion:\s*reduce\)[\s\S]*?\.startup-splash\s*\{[^}]*animation-duration:\s*300ms;/s',
            $stylesheet,
        );
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

    public function test_chatbot_branding_assets_are_referenced_without_embedding_them(): void
    {
        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $themeStylesheet = file_get_contents(resource_path('css/theme.css'));

        $this->assertFileExists(public_path(
            'images/branding/neomatra-logo.png',
        ));
        $this->assertFileExists(public_path(
            'images/branding/neomatra-wordmark-bg.png',
        ));
        $this->assertIsString($stylesheet);
        $this->assertIsString($themeStylesheet);
        $this->assertStringContainsString(
            '.console-panel::before',
            $stylesheet,
        );
        $this->assertStringContainsString(
            'background-image: var(--console-wordmark)',
            $stylesheet,
        );
        $this->assertStringContainsString(
            'pointer-events: none',
            $stylesheet,
        );
        $this->assertStringContainsString('opacity: 0.16', $stylesheet);
        $this->assertStringContainsString(
            '@keyframes brand-mark-full',
            $themeStylesheet,
        );
        $this->assertStringContainsString(
            '@media (prefers-reduced-motion: reduce)',
            $themeStylesheet,
        );
        $this->assertStringNotContainsString('data:image/', $stylesheet);
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
        $this->assertStringContainsString("t('searching'", $javascript);
        $this->assertStringContainsString("t('send'", $javascript);
        $this->assertStringContainsString(
            'appendSearchPipelineLoading',
            $javascript,
        );
        $this->assertStringContainsString(
            'createSearchPipelineLifecycle',
            $javascript.$client,
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
            "iframe.title = t('google_title'",
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
