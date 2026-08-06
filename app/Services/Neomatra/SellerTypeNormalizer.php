<?php

namespace App\Services\Neomatra;

use App\Helpers\Normalizer;
use App\Models\Listing;

final class SellerTypeNormalizer
{
    /** @var array<string, list<string>> */
    private const PHRASES = [
        Listing::SELLER_OFFICIAL => [
            'official store',
            'official shop',
            'toko resmi',
            'authorized store',
            'authorized dealer',
        ],
        Listing::SELLER_RESELLER => [
            'reseller',
            'dealer',
        ],
        Listing::SELLER_STORE => [
            'toko',
            'store',
            'shop',
        ],
        Listing::SELLER_INDIVIDUAL => [
            'individual',
            'perorangan',
            'pribadi',
            'pemakai pribadi',
            'personal seller',
        ],
    ];

    public function normalize(?string $sellerDescription): string
    {
        $text = Normalizer::text((string) $sellerDescription);

        if (in_array($text, Listing::sellerTypes(), true)) {
            return $text;
        }

        foreach (self::PHRASES as $sellerType => $phrases) {
            foreach ($phrases as $phrase) {
                if ($this->containsPhrase($text, $phrase)) {
                    return $sellerType;
                }
            }
        }

        return Listing::SELLER_UNKNOWN;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        return preg_match(
            '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u',
            $text,
        ) === 1;
    }
}
