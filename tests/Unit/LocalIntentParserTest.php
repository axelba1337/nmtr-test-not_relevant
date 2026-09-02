<?php

namespace Tests\Unit;

use App\Data\Search\SearchState;
use App\Services\Search\LocalIntentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LocalIntentParserTest extends TestCase
{
    #[DataProvider('conditionCases')]
    public function test_sc01_condition_variants_are_normalized(
        string $query,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            app(LocalIntentParser::class)->parse($query)['condition'],
        );
    }

    #[DataProvider('budgetCases')]
    public function test_sc02_budget_variants_are_normalized(
        string $query,
        int $expected,
    ): void {
        $this->assertSame(
            $expected,
            app(LocalIntentParser::class)->parse($query)['budget_max'],
        );
    }

    public function test_sc03_iphone_identity_and_location_are_parsed_without_seller_classification(): void
    {
        $parsed = app(LocalIntentParser::class)->parse(
            'Cari iPhone 13 Pro 128GB bekas di Jakarta dari toko resmi',
        );

        $this->assertSame('apple', $parsed['brand']);
        $this->assertSame('iphone 13 pro', $parsed['model']);
        $this->assertSame('128GB PRO', $parsed['variant']);
        $this->assertSame('used', $parsed['condition']);
        $this->assertArrayNotHasKey('seller_type', $parsed);
        $this->assertArrayNotHasKey('mode', $parsed);
        $this->assertSame('Jakarta', $parsed['location']);
    }

    public function test_sc04_all_required_search_states_are_declared(): void
    {
        $this->assertSame([
            'MATCHED',
            'NEEDS_CLARIFICATION',
            'FILTER_TOO_STRICT',
            'ALTERNATIVE_PRODUCT',
            'NO_ACTIVE_LISTING',
            'UNKNOWN_PRODUCT',
            'OUT_OF_SCOPE',
            'PLATFORM_UNAVAILABLE',
            'PARTIAL_RESULT',
            'SEARCH_TIMEOUT',
            'PROVIDER_ERROR',
            'INVALID_INPUT',
        ], SearchState::all());
    }

    public function test_platform_alias_does_not_leak_into_seller_type(): void
    {
        $parsed = app(LocalIntentParser::class)->parse(
            'tokobagus RTX 3070',
        );

        $this->assertSame('olx', $parsed['platform']);
        $this->assertArrayNotHasKey('seller_type', $parsed);
        $this->assertArrayNotHasKey('mode', $parsed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function conditionCases(): iterable
    {
        yield 'bekas' => ['RTX 3060 bekas', 'used'];
        yield 'secondhand' => ['RTX 3060 secondhand', 'used'];
        yield 'pre-owned' => ['RTX 3060 pre-owned', 'used'];
        yield 'seken' => ['RTX 3060 seken', 'used'];
        yield 'brand new' => ['iPhone brand new', 'new'];
        yield 'new' => ['iPhone new', 'new'];
        yield 'open-box' => ['Laptop open-box', 'open_box'];
        yield 'open_box' => ['Laptop open_box', 'open_box'];
        yield 'refurb' => ['Laptop refurb', 'refurbished'];
        yield 'ex-display' => ['Monitor ex-display', 'ex_display'];
    }

    /** @return iterable<string, array{string, int}> */
    public static function budgetCases(): iterable
    {
        yield 'juta' => ['di bawah Rp3 juta', 3_000_000];
        yield 'rupiah juta standalone' => ['RTX 3060 Rp3 juta', 3_000_000];
        yield 'juta standalone' => ['RTX 3060 3 juta', 3_000_000];
        yield 'jt' => ['budget 3jt', 3_000_000];
        yield 'jt standalone' => ['RTX 3060 3 jt', 3_000_000];
        yield 'integer' => ['maksimal 3000000', 3_000_000];
        yield 'integer standalone' => ['RTX 3060 3000000', 3_000_000];
        yield 'thousand separator' => ['maksimal Rp3.000.000', 3_000_000];
        yield 'separator standalone' => ['RTX 3060 3.000.000', 3_000_000];
        yield 'english million' => ['under 3 million', 3_000_000];
        yield 'below rupiah m' => ['below Rp3m', 3_000_000];
        yield 'symbol' => ['< 3 juta', 3_000_000];
        yield 'maks' => ['maks 3 juta', 3_000_000];
        yield 'decimal comma' => ['max Rp2,8 juta', 2_800_000];
    }
}
