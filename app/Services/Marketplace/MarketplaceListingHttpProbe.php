<?php

namespace App\Services\Marketplace;

use App\Data\Marketplace\MarketplaceListingProbeResult;
use App\Models\Listing;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

final class MarketplaceListingHttpProbe
{
    public function probe(string $url): MarketplaceListingProbeResult
    {
        if (! (bool) config(
            'listing-discovery.http_probe.enabled',
            true,
        )) {
            return MarketplaceListingProbeResult::skipped();
        }

        $timeout = min(60, max(1, (int) config(
            'listing-discovery.http_probe.timeout',
        )));

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->withHeaders([
                    'User-Agent' => 'NeomatraListingProbe/1.0',
                ])
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->withoutRedirecting()
                ->get($url);
        } catch (Throwable) {
            return MarketplaceListingProbeResult::failed();
        }

        if (! $response->successful()) {
            return MarketplaceListingProbeResult::failed();
        }

        $body = $response->body();

        if (trim($body) === '' || ! class_exists(DOMDocument::class)) {
            return MarketplaceListingProbeResult::failed();
        }

        $metadata = $this->metadata(mb_substr($body, 0, 1_000_000, 'UTF-8'));

        return new MarketplaceListingProbeResult(
            true,
            true,
            $metadata['title'],
            $metadata['description'],
            $metadata['price'],
            $metadata['currency'],
            $metadata['availability'],
            $metadata['image_url'],
        );
    }

    /**
     * @return array{
     *     title:?string,
     *     description:?string,
     *     price:?int,
     *     currency:?string,
     *     availability:?string,
     *     image_url:?string
     * }
     */
    private function metadata(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return $this->emptyMetadata();
        }

        $xpath = new DOMXPath($dom);
        $meta = [];

        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $key = strtolower(trim(
                $node->getAttribute('property') ?: $node->getAttribute('name'),
            ));
            $value = trim($node->getAttribute('content'));

            if ($key !== '' && $value !== '') {
                $meta[$key] = $value;
            }
        }

        $jsonLd = $this->jsonLd($xpath);
        $currency = $this->cleanText(
            $jsonLd['currency']
                ?? $meta['product:price:currency']
                ?? $meta['og:price:currency']
                ?? null,
            3,
        );
        $currency = $currency !== null ? strtoupper($currency) : null;
        $price = $this->structuredPrice(
            $jsonLd['price']
                ?? $meta['product:price:amount']
                ?? $meta['og:price:amount']
                ?? null,
        );

        if ($currency !== null && $currency !== 'IDR') {
            $price = null;
        }

        return [
            'title' => $this->cleanText(
                $jsonLd['title']
                    ?? $meta['og:title']
                    ?? $xpath->evaluate('string(//title[1])'),
                255,
            ),
            'description' => $this->cleanText(
                $jsonLd['description']
                    ?? $meta['og:description']
                    ?? $meta['description']
                    ?? null,
                10_000,
            ),
            'price' => $price,
            'currency' => $currency,
            'availability' => $this->cleanText(
                $jsonLd['availability']
                    ?? $meta['product:availability']
                    ?? null,
                100,
            ),
            'image_url' => $this->httpUrl(
                $jsonLd['image'] ?? $meta['og:image'] ?? null,
            ),
        ];
    }

    /** @return array<string, scalar|null> */
    private function jsonLd(DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode((string) $node->textContent, true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->jsonLdNodes($decoded) as $entry) {
                $type = strtolower((string) ($entry['@type'] ?? ''));

                if (! in_array($type, ['product', 'offer', 'aggregateoffer'], true)) {
                    continue;
                }

                $offer = $entry['offers'] ?? $entry;

                if (isset($offer[0]) && is_array($offer[0])) {
                    $offer = $offer[0];
                }

                if (! is_array($offer)) {
                    $offer = [];
                }

                $image = $entry['image'] ?? null;

                if (is_array($image)) {
                    $image = $image['url'] ?? $image[0] ?? null;
                }

                return [
                    'title' => $entry['name'] ?? null,
                    'description' => $entry['description'] ?? null,
                    'price' => $offer['price'] ?? $offer['lowPrice'] ?? null,
                    'currency' => $offer['priceCurrency'] ?? null,
                    'availability' => $offer['availability'] ?? null,
                    'image' => $image,
                ];
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private function jsonLdNodes(array $value): array
    {
        $nodes = [];

        if (isset($value['@type'])) {
            $nodes[] = $value;
        }

        foreach ($value as $nested) {
            if (is_array($nested)) {
                $nodes = array_merge($nodes, $this->jsonLdNodes($nested));
            }
        }

        return $nodes;
    }

    private function structuredPrice(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        } elseif (preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        $price = (int) floor((float) str_replace(',', '.', $value));

        return $price > 0 && $price <= Listing::MAX_MONEY ? $price : null;
    }

    private function cleanText(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

        return $value === ''
            ? null
            : mb_substr($value, 0, $maximum, 'UTF-8');
    }

    private function httpUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $value
            : null;
    }

    /** @return array{title:null,description:null,price:null,currency:null,availability:null,image_url:null} */
    private function emptyMetadata(): array
    {
        return [
            'title' => null,
            'description' => null,
            'price' => null,
            'currency' => null,
            'availability' => null,
            'image_url' => null,
        ];
    }
}
