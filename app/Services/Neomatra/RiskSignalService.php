<?php

namespace App\Services\Neomatra;

final class RiskSignalService
{
    public const SIGNAL_NONE = 'none';

    public const SIGNAL_REVIEW = 'needs_review';

    public const SIGNAL_HIGH = 'price_anomaly';

    /**
     * SRS only defines price-based signals at 20-35% and above 35%.
     * No seller or product safety claim is inferred here.
     *
     * @return array{
     *     signal: string,
     *     label: string,
     *     requires_review: bool,
     *     disclaimer: string
     * }
     */
    public function fromUndervaluedPercentage(float $percentage): array
    {
        if ($percentage > 35) {
            return [
                'signal' => self::SIGNAL_HIGH,
                'label' => 'Anomali harga atau risiko tinggi',
                'requires_review' => true,
                'disclaimer' => $this->disclaimer(),
            ];
        }

        if ($percentage >= 20) {
            return [
                'signal' => self::SIGNAL_REVIEW,
                'label' => 'Perlu pemeriksaan lebih lanjut',
                'requires_review' => true,
                'disclaimer' => $this->disclaimer(),
            ];
        }

        return [
            'signal' => self::SIGNAL_NONE,
            'label' => 'Tidak ada sinyal harga khusus',
            'requires_review' => false,
            'disclaimer' => $this->disclaimer(),
        ];
    }

    private function disclaimer(): string
    {
        return 'Kondisi, seller, harga, dan margin merupakan informasi atau estimasi, bukan jaminan keamanan maupun keuntungan.';
    }
}
