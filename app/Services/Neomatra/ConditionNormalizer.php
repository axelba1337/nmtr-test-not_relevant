<?php

namespace App\Services\Neomatra;

use App\Helpers\Normalizer;
use App\Models\Listing;

final class ConditionNormalizer
{
    /** @var array<string, list<string>> */
    private const PHRASES = [
        Listing::CONDITION_REFURBISHED => [
            'refurbished',
            'rekondisi',
            'reconditioned',
            'repaired',
            'pernah diperbaiki',
        ],
        Listing::CONDITION_EX_DISPLAY => [
            'ex display',
            'ex-display',
            'ex demo',
            'ex-demo',
            'barang display',
            'pajangan toko',
        ],
        Listing::CONDITION_OPEN_BOX => [
            'open box',
            'open-box',
            'baru dibuka',
            'hanya dibuka',
            'cek isi',
        ],
        Listing::CONDITION_NEW => [
            'baru',
            'sealed',
            'bnib',
            'unused',
            'brand new',
            'belum pernah dipakai',
        ],
        Listing::CONDITION_USED => [
            'second',
            'bekas',
            'used',
            'pemakaian',
            'personal use',
            'pemakaian pribadi',
        ],
    ];

    public function normalize(?string $description): string
    {
        $text = Normalizer::text((string) $description);

        if (in_array($text, Listing::conditions(), true)) {
            return $text;
        }

        foreach (self::PHRASES as $condition => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->containsPhrase($text, $phrase)) {
                    return $condition;
                }
            }
        }

        return Listing::CONDITION_UNKNOWN;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u',
            $text,
        ) === 1;
    }
}
