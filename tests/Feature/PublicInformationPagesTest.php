<?php

namespace Tests\Feature;

use Tests\TestCase;

final class PublicInformationPagesTest extends TestCase
{
    public function test_public_footer_links_to_localized_information_pages_without_admin_link(): void
    {
        $this->withoutVite();

        $this->withSession(['locale' => 'id'])
            ->get(route('chatbot'))
            ->assertOk()
            ->assertSeeText('Tentang Neomatra')
            ->assertSeeText('Kontak Dukungan')
            ->assertSee('href="'.route('about').'"', false)
            ->assertSee('href="'.route('contact').'"', false)
            ->assertDontSee('href="'.route('admin.login').'"', false);

        $this->withSession(['locale' => 'en'])
            ->get(route('chatbot'))
            ->assertOk()
            ->assertSeeText('About Neomatra')
            ->assertSeeText('Contact Support')
            ->assertDontSeeText('Kontak Dukungan');

        $this->get(route('admin.login'))->assertOk();
    }

    public function test_about_page_is_public_localized_and_links_back_to_chatbot(): void
    {
        $this->withoutVite();

        $this->withSession(['locale' => 'id'])
            ->get(route('about'))
            ->assertOk()
            ->assertSeeText('Tentang Neomatra')
            ->assertSeeText('PC Components, Handphone, Laptop, PC Accessories, dan Networking.')
            ->assertSeeText('Tokopedia, Shopee, OLX, Facebook Marketplace, dan Carousell.')
            ->assertSeeText('Kembali ke Chatbot')
            ->assertSee('href="'.route('chatbot').'"', false)
            ->assertDontSee('ui.public.', false);

        $this->withSession(['locale' => 'en'])
            ->get(route('about'))
            ->assertOk()
            ->assertSeeText('About Neomatra')
            ->assertSeeText('PC Components, Mobile Phones, Laptops, PC Accessories, and Networking.')
            ->assertSeeText('Tokopedia, Shopee, OLX, Facebook Marketplace, and Carousell.')
            ->assertSeeText('Back to Chatbot')
            ->assertDontSee('ui.public.', false);
    }

    public function test_contact_page_has_safe_clickable_contact_links_and_back_navigation(): void
    {
        $this->withoutVite();

        foreach (['id', 'en'] as $locale) {
            $this->withSession(['locale' => $locale])
                ->get(route('contact'))
                ->assertOk()
                ->assertSeeText('ardiansahbelliandriaxel@gmail.com')
                ->assertSee('href="mailto:ardiansahbelliandriaxel@gmail.com"', false)
                ->assertSee('href="https://www.linkedin.com/in/axel-belliandri-220620276/" target="_blank" rel="noopener noreferrer"', false)
                ->assertSee('href="https://www.instagram.com/speirs.dev/" target="_blank" rel="noopener noreferrer"', false)
                ->assertSee('href="'.route('chatbot').'"', false)
                ->assertDontSee('ui.public.', false);
        }
    }

    public function test_public_footer_and_information_layout_have_mobile_rules(): void
    {
        $blade = file_get_contents(resource_path('views/chatbot/widget.blade.php'));
        $stylesheet = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($blade);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('class="public-footer"', $blade);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 34rem\).*?\.public-footer\s*\{[^}]*flex-direction:\s*column;/s',
            $stylesheet,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 48rem\).*?\.public-info-grid\s*\{[^}]*grid-template-columns:\s*1fr;/s',
            $stylesheet,
        );
    }
}
