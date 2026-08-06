<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Services\Neomatra\ConditionNormalizer;
use App\Services\Neomatra\MarketPriceCalculator;
use App\Services\Neomatra\RankingCalculator;
use App\Services\Neomatra\ResellerMarginCalculator;
use App\Services\Neomatra\RiskSignalService;
use App\Services\Neomatra\SellerTypeNormalizer;
use App\Services\Neomatra\TotalCostCalculator;
use App\Services\Neomatra\UndervaluedCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NeomatraCalculationsTest extends TestCase
{
    public function test_it_reproduces_the_exact_srs_calculation_example(): void
    {
        $totalCost = (new TotalCostCalculator)->calculate(
            2_500_000,
            25_000,
            20_000,
            0,
            50_000,
        );
        $undervalued = (new UndervaluedCalculator)->calculate(
            3_000_000,
            $totalCost,
        );
        $margin = (new ResellerMarginCalculator)->calculate(
            3_100_000,
            $totalCost,
            75_000,
            0,
        );

        $this->assertSame(2_595_000, $totalCost);
        $this->assertEqualsWithDelta(
            13.5,
            $undervalued['percentage'],
            0.000001,
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_UNDERVALUED,
            $undervalued['label'],
        );
        $this->assertSame(430_000, $margin['net_margin']);
        $this->assertEqualsWithDelta(
            (430_000 / 2_595_000) * 100,
            $margin['margin_percent'],
            0.000001,
        );
    }

    public function test_market_price_uses_median_and_excludes_invalid_rows(): void
    {
        $calculator = new MarketPriceCalculator;
        $marketPrice = $calculator->calculate([
            [
                'price' => 2_000_000,
                'source_fingerprint' => 'valid-a',
                'is_active' => true,
                'stock_status' => Listing::STOCK_IN_STOCK,
                'price_type' => Listing::PRICE_FULL,
            ],
            [
                'price' => 2_000_000,
                'source_fingerprint' => 'valid-a',
            ],
            [
                'price' => 4_000_000,
                'source_fingerprint' => 'valid-b',
            ],
            [
                'price' => 1,
                'source_fingerprint' => 'out-of-stock',
                'stock_status' => Listing::STOCK_OUT_OF_STOCK,
            ],
            [
                'price' => 1,
                'source_fingerprint' => 'installment',
                'price_type' => Listing::PRICE_INSTALLMENT,
            ],
            [
                'price' => 1,
                'source_fingerprint' => 'inactive',
                'is_active' => false,
            ],
            [
                'price' => 0,
                'source_fingerprint' => 'zero',
            ],
        ]);

        $this->assertSame(3_000_000, $marketPrice);
        $this->assertSame(102, $calculator->median([100, 103]));
        $this->assertNull($calculator->median([0, -1]));
    }

    public function test_undervalued_labels_and_risk_use_the_srs_boundaries(): void
    {
        $undervalued = new UndervaluedCalculator;
        $risk = new RiskSignalService;

        $this->assertSame(
            UndervaluedCalculator::LABEL_NORMAL,
            $undervalued->label(4.999),
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_SLIGHTLY_BELOW,
            $undervalued->label(5.0),
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_UNDERVALUED,
            $undervalued->label(10.0),
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_VERY_UNDERVALUED,
            $undervalued->label(20.0),
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_VERY_UNDERVALUED,
            $undervalued->label(35.0),
        );
        $this->assertSame(
            UndervaluedCalculator::LABEL_PRICE_ANOMALY,
            $undervalued->label(35.001),
        );

        $this->assertSame(
            RiskSignalService::SIGNAL_NONE,
            $risk->fromUndervaluedPercentage(19.999)['signal'],
        );
        $this->assertSame(
            RiskSignalService::SIGNAL_REVIEW,
            $risk->fromUndervaluedPercentage(20.0)['signal'],
        );
        $this->assertSame(
            RiskSignalService::SIGNAL_REVIEW,
            $risk->fromUndervaluedPercentage(35.0)['signal'],
        );
        $this->assertSame(
            RiskSignalService::SIGNAL_HIGH,
            $risk->fromUndervaluedPercentage(35.001)['signal'],
        );
    }

    public function test_ranking_uses_the_exact_srs_weights(): void
    {
        $calculator = new RankingCalculator;
        $score = $calculator->calculate([
            RankingCalculator::SCORE_RELEVANCE => 0.80,
            RankingCalculator::SCORE_PRICE => 0.60,
            RankingCalculator::SCORE_CONDITION => 0.70,
            RankingCalculator::SCORE_SELLER => 0.50,
            RankingCalculator::SCORE_FRESHNESS => 1.00,
        ]);

        $this->assertEqualsWithDelta(0.705, $score, 0.000001);

        $this->expectException(InvalidArgumentException::class);
        $calculator->calculate(
            array_fill_keys(array_keys(RankingCalculator::DEFAULT_WEIGHTS), 1),
            [
                RankingCalculator::SCORE_RELEVANCE => 0.40,
                RankingCalculator::SCORE_PRICE => 0.30,
                RankingCalculator::SCORE_CONDITION => 0.15,
                RankingCalculator::SCORE_SELLER => 0.10,
                RankingCalculator::SCORE_FRESHNESS => 0.10,
            ],
        );
    }

    public function test_condition_and_seller_normalization_is_deterministic(): void
    {
        $conditions = new ConditionNormalizer;
        $sellers = new SellerTypeNormalizer;

        $this->assertSame(
            Listing::CONDITION_OPEN_BOX,
            $conditions->normalize('Barang baru dibuka untuk cek isi'),
        );
        $this->assertSame(
            Listing::CONDITION_REFURBISHED,
            $conditions->normalize('Unit rekondisi dan pernah diperbaiki'),
        );
        $this->assertSame(
            Listing::CONDITION_EX_DISPLAY,
            $conditions->normalize('Barang ex-display toko'),
        );
        $this->assertSame(
            Listing::CONDITION_USED,
            $conditions->normalize('Bekas pemakaian pribadi'),
        );
        $this->assertSame(
            Listing::CONDITION_UNKNOWN,
            $conditions->normalize('Kondisi tidak dijelaskan'),
        );

        $this->assertSame(
            Listing::SELLER_OFFICIAL,
            $sellers->normalize('NVIDIA Official Store'),
        );
        $this->assertSame(
            Listing::SELLER_RESELLER,
            $sellers->normalize('Penjual reseller hardware'),
        );
        $this->assertSame(
            Listing::SELLER_INDIVIDUAL,
            $sellers->normalize('Pemakai pribadi'),
        );
        $this->assertSame(
            Listing::SELLER_UNKNOWN,
            $sellers->normalize('Tidak disebutkan'),
        );
    }
}
