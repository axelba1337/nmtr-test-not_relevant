<?php

namespace Tests\Unit;

use App\Helpers\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    public function test_it_normalizes_case_and_unicode_whitespace(): void
    {
        $this->assertSame(
            'halo dunia café',
            Normalizer::text(" \nHALO\u{00A0}\tDUNIA Café \r\n"),
        );
    }

    public function test_it_returns_an_empty_string_for_whitespace_only_input(): void
    {
        $this->assertSame('', Normalizer::text(" \t\n\u{2003} "));
    }
}
