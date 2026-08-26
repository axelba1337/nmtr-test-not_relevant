<?php

namespace App\Services\Search;

use App\Helpers\Normalizer;

final class ProductFamilyResolver
{
    /** @return array<string, mixed>|null */
    public function resolve(string $text): ?array
    {
        $text = Normalizer::text($text);

        foreach ($this->patterns() as $definition) {
            if (preg_match($definition['pattern'], $text, $match) !== 1) {
                continue;
            }

            $number = isset($match['number']) && $match['number'] !== ''
                ? (int) $match['number']
                : null;

            return [
                'category' => $definition['category'],
                'category_slug' => $definition['category_slug'],
                'brand' => $definition['brand'],
                'brand_family' => $definition['brand_family'],
                'family' => $definition['family'],
                'family_label' => $definition['family_label'],
                'generation' => $number === null
                    ? null
                    : $this->generation($definition['family'], $number),
                'model_number' => $number,
            ];
        }

        return null;
    }

    /** @return list<array<string, string>> */
    private function patterns(): array
    {
        return [
            [
                'pattern' => '/\brtx(?:\s*(?<number>\d{3,4}))?\b/u',
                'category' => 'GPU', 'category_slug' => 'gpu',
                'brand' => 'NVIDIA', 'brand_family' => 'geforce',
                'family' => 'geforce_rtx', 'family_label' => 'GeForce RTX',
            ],
            [
                'pattern' => '/\bgtx(?:\s*(?<number>\d{3,4}))?\b/u',
                'category' => 'GPU', 'category_slug' => 'gpu',
                'brand' => 'NVIDIA', 'brand_family' => 'geforce',
                'family' => 'geforce_gtx', 'family_label' => 'GeForce GTX',
            ],
            [
                'pattern' => '/\b(?:radeon\s*)?rx(?:\s*(?<number>\d{3,4}))?\b/u',
                'category' => 'GPU', 'category_slug' => 'gpu',
                'brand' => 'AMD', 'brand_family' => 'radeon',
                'family' => 'radeon_rx', 'family_label' => 'Radeon RX',
            ],
            [
                'pattern' => '/\bryzen(?:\s*[3579])?(?:\s*(?<number>\d{4}))?\b/u',
                'category' => 'CPU', 'category_slug' => 'cpu',
                'brand' => 'AMD', 'brand_family' => 'ryzen',
                'family' => 'ryzen', 'family_label' => 'Ryzen',
            ],
            [
                'pattern' => '/\b(?:core\s*)?i[3579](?:[ -]?(?<number>\d{4,5}))?\b/u',
                'category' => 'CPU', 'category_slug' => 'cpu',
                'brand' => 'Intel', 'brand_family' => 'core',
                'family' => 'intel_core', 'family_label' => 'Core',
            ],
            [
                'pattern' => '/\biphone(?:\s*(?<number>\d{1,2}))?\b/u',
                'category' => 'Smartphone', 'category_slug' => 'smartphone',
                'brand' => 'Apple', 'brand_family' => 'iphone',
                'family' => 'iphone', 'family_label' => 'iPhone',
            ],
            [
                'pattern' => '/\bgalaxy\b(?:\s*[a-z]?\s*(?<number>\d{1,3}))?/u',
                'category' => 'Smartphone', 'category_slug' => 'smartphone',
                'brand' => 'Samsung', 'brand_family' => 'galaxy',
                'family' => 'galaxy', 'family_label' => 'Galaxy',
            ],
        ];
    }

    private function generation(string $family, int $number): ?string
    {
        return match ($family) {
            'geforce_rtx' => 'rtx_'.intdiv($number, 100),
            'geforce_gtx' => 'gtx_'.intdiv($number, 100),
            'radeon_rx' => 'rx_'.(intdiv($number, 1000) * 1000),
            'ryzen' => 'ryzen_'.(intdiv($number, 1000) * 1000),
            'intel_core' => 'core_'.intdiv($number, 1000),
            'iphone' => 'iphone_'.$number,
            'galaxy' => 'galaxy_'.$number,
            default => null,
        };
    }
}
