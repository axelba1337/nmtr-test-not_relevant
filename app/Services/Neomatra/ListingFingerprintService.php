<?php

namespace App\Services\Neomatra;

use App\Helpers\Normalizer;

final class ListingFingerprintService
{
    /** @var list<string> */
    private const TRACKING_QUERY_KEYS = [
        'fbclid',
        'gclid',
        'mc_cid',
        'mc_eid',
        'ref',
        'source',
        'utm_campaign',
        'utm_content',
        'utm_medium',
        'utm_source',
        'utm_term',
    ];

    public function make(
        string|int $platformKey,
        ?string $externalListingId,
        ?string $productUrl,
        string $title,
        int $price,
        ?string $sellerName = null,
    ): string {
        $platform = Normalizer::text((string) $platformKey);
        $externalId = Normalizer::text((string) $externalListingId);

        if ($externalId !== '') {
            return $this->hash(['external', $platform, $externalId]);
        }

        $canonicalUrl = $this->canonicalUrl((string) $productUrl);

        if ($canonicalUrl !== null) {
            return $this->hash(['url', $platform, $canonicalUrl]);
        }

        return $this->hash([
            'fallback',
            $platform,
            Normalizer::text($title),
            (string) $price,
            Normalizer::text((string) $sellerName),
        ]);
    }

    public function canonicalUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = '/'.ltrim((string) ($parts['path'] ?? ''), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (array_keys($query) as $key) {
                if (in_array(strtolower((string) $key), self::TRACKING_QUERY_KEYS, true)) {
                    unset($query[$key]);
                }
            }

            ksort($query);
        }

        $queryString = $query !== []
            ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            : '';

        return $scheme.'://'.$host.$port.$path.$queryString;
    }

    /** @param list<string> $values */
    private function hash(array $values): string
    {
        return hash('sha256', implode('|', array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            $values,
        )));
    }
}
