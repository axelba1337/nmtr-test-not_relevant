<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ListingDiscoveryConfigurationTest extends TestCase
{
    #[Test]
    public function runtime_resolves_the_intended_provider_and_batch_configuration(): void
    {
        $this->assertSame('gemini', config('ai.chat.provider'));
        $this->assertSame('gemini-3.1-flash-lite', config('ai.chat.model'));
        $this->assertSame('gemini', config('ai.embedding.provider'));
        $this->assertSame('gemini-embedding-2', config('ai.embedding.model'));
        $this->assertSame(768, config('ai.embedding.dimensions'));
        $this->assertSame(30, config('ai.http.timeout'));
        $this->assertSame('tavily', config('listing-discovery.provider'));
        $this->assertSame(100, config('listing-discovery.max_products_per_run'));
        $this->assertSame(3, config('listing-discovery.target_active_listings'));
        $this->assertSame(2, config('listing-discovery.minimum_active_listings'));
        $this->assertSame(25, config('listing-discovery.research_prompt_max_products'));
        $this->assertSame(24, config('listing-discovery.retry_cooldown_hours'));
        $this->assertSame(3, config('listing-discovery.max_new_listings_per_product'));
        $this->assertSame(2, config('listing-discovery.max_per_platform_per_product'));
        $this->assertTrue(config('listing-discovery.require_approval'));
        $this->assertFalse(config('listing-discovery.auto_import'));
        $this->assertFalse(config('listing-discovery.schedule.enabled'));
        $this->assertSame('basic', config('listing-discovery.tavily.search_depth'));
        $this->assertSame(20, config('listing-discovery.tavily.search_max_results'));
        $this->assertTrue(config('listing-discovery.tavily.extract_enabled'));
        $this->assertTrue(config('listing-discovery.tavily.extract_fallback_only'));
        $this->assertTrue(config('listing-discovery.http_probe.enabled'));
        $this->assertSame(8, config('listing-discovery.http_probe.timeout'));
        $this->assertTrue(config('listing-discovery.platform_fallback.enabled'));
        $this->assertSame(2, config('listing-discovery.platform_fallback.max_searches_per_product'));
        $this->assertSame(24, config('listing-discovery.search_cache_hours'));
    }

    #[Test]
    public function env_example_documents_safe_current_defaults(): void
    {
        $entries = $this->entries(base_path('.env.example'));

        $this->assertSame('', $entries['APP_KEY']);
        $this->assertSame('http://127.0.0.1:8000', $entries['APP_URL']);
        $this->assertSame('', $entries['GEMINI_API_KEY']);
        $this->assertSame('', $entries['TAVILY_API_KEY']);
        $this->assertSame('"${GEMINI_API_KEY}"', $entries['CHAT_API_KEY']);
        $this->assertSame('"${GEMINI_API_KEY}"', $entries['EMBEDDING_API_KEY']);
        $this->assertSame('30', $entries['AI_HTTP_TIMEOUT']);
        $this->assertSame('tavily', $entries['LISTING_DISCOVERY_PROVIDER']);
        $this->assertSame('100', $entries['LISTING_DISCOVERY_MAX_PRODUCTS_PER_RUN']);
        $this->assertSame('24', $entries['LISTING_DISCOVERY_RETRY_COOLDOWN_HOURS']);
        $this->assertSame('3', $entries['LISTING_DISCOVERY_MAX_NEW_LISTINGS_PER_PRODUCT']);
        $this->assertSame('2', $entries['LISTING_DISCOVERY_MAX_PER_PLATFORM_PER_PRODUCT']);
        $this->assertSame('3', $entries['LISTING_DISCOVERY_TARGET_ACTIVE_LISTINGS']);
        $this->assertSame('true', $entries['LISTING_DISCOVERY_REQUIRE_APPROVAL']);
        $this->assertSame('false', $entries['LISTING_DISCOVERY_AUTO_IMPORT']);
        $this->assertSame('false', $entries['LISTING_DISCOVERY_SCHEDULE_ENABLED']);
        $this->assertSame('20', $entries['TAVILY_SEARCH_MAX_RESULTS']);
        $this->assertSame('true', $entries['TAVILY_EXTRACT_FALLBACK_ONLY']);
        $this->assertSame('true', $entries['LISTING_DISCOVERY_HTTP_PROBE_ENABLED']);
        $this->assertSame('8', $entries['LISTING_DISCOVERY_HTTP_PROBE_TIMEOUT']);
        $this->assertSame('true', $entries['LISTING_DISCOVERY_PLATFORM_FALLBACK_ENABLED']);
        $this->assertSame('2', $entries['LISTING_DISCOVERY_PLATFORM_FALLBACK_MAX_SEARCHES_PER_PRODUCT']);
        $this->assertSame('24', $entries['LISTING_DISCOVERY_SEARCH_CACHE_HOURS']);
        $this->assertSame('database', $entries['QUEUE_CONNECTION']);
    }

    #[Test]
    public function active_discovery_keys_are_not_duplicated_in_environment_files(): void
    {
        $keys = [
            'LISTING_DISCOVERY_ENABLED',
            'LISTING_DISCOVERY_PROVIDER',
            'LISTING_DISCOVERY_MAX_PRODUCTS_PER_RUN',
            'LISTING_DISCOVERY_TARGET_ACTIVE_LISTINGS',
            'LISTING_DISCOVERY_SCHEDULE_ENABLED',
            'TAVILY_SEARCH_MAX_RESULTS',
            'TAVILY_EXTRACT_ENABLED',
            'TAVILY_EXTRACT_FALLBACK_ONLY',
            'TAVILY_EXTRACT_MAX_URLS_PER_PRODUCT',
            'LISTING_DISCOVERY_HTTP_PROBE_ENABLED',
            'LISTING_DISCOVERY_HTTP_PROBE_TIMEOUT',
            'LISTING_DISCOVERY_PLATFORM_FALLBACK_ENABLED',
            'LISTING_DISCOVERY_PLATFORM_FALLBACK_MAX_SEARCHES_PER_PRODUCT',
            'LISTING_DISCOVERY_SEARCH_CACHE_HOURS',
        ];
        $files = [base_path('.env.example')];

        if (is_file(base_path('.env'))) {
            $files[] = base_path('.env');
        }

        foreach ($files as $file) {
            $counts = $this->keyCounts($file);

            foreach ($keys as $key) {
                $this->assertSame(1, $counts[$key] ?? 0, basename($file).': '.$key);
            }
        }
    }

    #[Test]
    public function dead_comparables_key_is_removed_but_referenced_legacy_fallbacks_remain_documented(): void
    {
        $example = $this->entries(base_path('.env.example'));

        $this->assertArrayNotHasKey('NEOMATRA_MINIMUM_COMPARABLES', $example);
        $this->assertArrayHasKey('GEMINI_CHAT_MODEL', $example);
        $this->assertArrayHasKey('GEMINI_EMBED_MODEL', $example);
        $this->assertArrayHasKey('GEMINI_EMBED_DIMENSIONS', $example);
        $this->assertArrayHasKey('OLX_BASE_URL', $example);
        $this->assertArrayHasKey('OLX_SEARCH_TERMS', $example);

        if (is_file(base_path('.env'))) {
            $this->assertArrayNotHasKey(
                'NEOMATRA_MINIMUM_COMPARABLES',
                $this->entries(base_path('.env')),
            );
        }
    }

    /** @return array<string, string> */
    private function entries(string $path): array
    {
        $entries = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $entries[trim($key)] = trim($value);
        }

        return $entries;
    }

    /** @return array<string, int> */
    private function keyCounts(string $path): array
    {
        $counts = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
