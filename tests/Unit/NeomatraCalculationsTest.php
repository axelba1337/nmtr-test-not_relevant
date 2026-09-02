<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Services\Neomatra\ConditionNormalizer;
use App\Services\Neomatra\FreshnessScoreCalculator;
use App\Services\Search\ListingRankingService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class NeomatraCalculationsTest extends TestCase
{
    public function test_current_mvp_ranking_uses_only_supported_factors(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');
        $ranking = new ListingRankingService(new FreshnessScoreCalculator);
        $listing = new Listing([
            'price' => 2_000_000,
            'condition' => Listing::CONDITION_USED,
            'seller_type' => Listing::SELLER_OFFICIAL,
            'location' => 'Surabaya',
            'source_published_at' => CarbonImmutable::now()->subHour(),
        ]);

        $result = $ranking->calculate(
            $listing,
            0.8,
            ['Surabaya'],
            2_000_000,
            3_000_000,
        );

        $this->assertSame(
            ['relevance', 'price', 'condition', 'location', 'freshness'],
            array_keys($result['scores']),
        );
        $this->assertSame(1.0, $result['scores']['price']);
        $this->assertSame(1.0, $result['scores']['location']);
        $this->assertArrayNotHasKey('seller', $result['scores']);
        $this->assertArrayNotHasKey('undervalued', $result['scores']);

        $listing->seller_type = Listing::SELLER_INDIVIDUAL;
        $changedSeller = $ranking->calculate(
            $listing,
            0.8,
            ['Surabaya'],
            2_000_000,
            3_000_000,
        );

        $this->assertSame($result, $changedSeller);

        $listing->setAttribute('legacy_undervalued_percentage', 99.0);
        $changedLegacyPriceSignal = $ranking->calculate(
            $listing,
            0.8,
            ['Surabaya'],
            2_000_000,
            3_000_000,
        );

        $this->assertSame($result, $changedLegacyPriceSignal);
        CarbonImmutable::setTestNow();
    }

    public function test_lower_raw_price_and_requested_location_improve_rank(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');
        $ranking = new ListingRankingService(new FreshnessScoreCalculator);
        $base = [
            'condition' => Listing::CONDITION_USED,
            'source_published_at' => CarbonImmutable::now()->subHour(),
        ];
        $lower = new Listing([...$base, 'price' => 2_000_000, 'location' => 'Surabaya']);
        $higher = new Listing([...$base, 'price' => 3_000_000, 'location' => 'Jakarta']);

        $lowerScore = $ranking->calculate($lower, 0.7, ['Surabaya'], 2_000_000, 3_000_000);
        $higherScore = $ranking->calculate($higher, 0.7, ['Surabaya'], 2_000_000, 3_000_000);

        $this->assertGreaterThan($higherScore['rank_score'], $lowerScore['rank_score']);
        $this->assertSame(0.0, $higherScore['scores']['price']);
        $this->assertSame(0.0, $higherScore['scores']['location']);
        CarbonImmutable::setTestNow();
    }

    public function test_condition_normalization_remains_deterministic(): void
    {
        $conditions = new ConditionNormalizer;

        $this->assertSame(Listing::CONDITION_OPEN_BOX, $conditions->normalize('Barang baru dibuka untuk cek isi'));
        $this->assertSame(Listing::CONDITION_REFURBISHED, $conditions->normalize('Unit rekondisi dan pernah diperbaiki'));
        $this->assertSame(Listing::CONDITION_EX_DISPLAY, $conditions->normalize('Barang ex-display toko'));
        $this->assertSame(Listing::CONDITION_USED, $conditions->normalize('Bekas pemakaian pribadi'));
        $this->assertSame(Listing::CONDITION_UNKNOWN, $conditions->normalize('Kondisi tidak dijelaskan'));
    }
}
