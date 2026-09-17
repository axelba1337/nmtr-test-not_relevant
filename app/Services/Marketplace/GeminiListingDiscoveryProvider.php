<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Data\Marketplace\ListingDiscoveryResult;
use App\Models\Product;
use App\Services\AI\GeminiHttpClient;
use Illuminate\Http\Client\Response;
use JsonException;

final class GeminiListingDiscoveryProvider implements ListingDiscoveryProvider
{
    public function __construct(
        private readonly GeminiHttpClient $http,
    ) {}

    public function discover(
        Product $product,
        ?int $maximumCandidates = null,
    ): ListingDiscoveryResult {
        if (! (bool) config(
            'listing-discovery.gemini.grounding_enabled',
            true,
        )) {
            return ListingDiscoveryResult::failed(
                'configuration',
                'Google Search grounding is disabled.',
            );
        }

        $model = trim((string) config(
            'listing-discovery.gemini.model',
            '',
        ));
        $response = $this->http->postFromConfiguration(
            'listing-discovery.gemini',
            'listing_discovery',
            $model,
            'models/'.rawurlencode($this->normalizedModel($model)).':generateContent',
            [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemInstruction()]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [[
                        'text' => $this->prompt(
                            $product,
                            $maximumCandidates,
                        ),
                    ]],
                ]],
                'tools' => [[
                    'googleSearch' => new \stdClass,
                ]],
                'generationConfig' => [
                    'maxOutputTokens' => min(8192, max(
                        256,
                        (int) config(
                            'listing-discovery.gemini.max_output_tokens',
                            4096,
                        ),
                    )),
                ],
            ],
        );

        if (! $response->succeeded()) {
            return ListingDiscoveryResult::failed(
                $response->failure?->category ?? 'provider_error',
                $response->failure?->safeMessage
                    ?? 'Listing discovery provider failed.',
            );
        }

        $text = $this->responseText($response->response);
        $sources = $this->groundingSources($response->response);

        if ($text === null || $sources === []) {
            return ListingDiscoveryResult::failed(
                'grounding_unavailable',
                'The discovery response did not include verifiable grounding.',
            );
        }

        try {
            $decoded = json_decode(
                $this->jsonText($text),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return ListingDiscoveryResult::failed(
                'invalid_response',
                'The discovery response was not valid structured data.',
            );
        }

        $candidates = is_array($decoded['candidates'] ?? null)
            ? $decoded['candidates']
            : (is_array($decoded) && array_is_list($decoded) ? $decoded : []);
        $candidates = array_values(array_filter(
            $candidates,
            static fn (mixed $candidate): bool => is_array($candidate),
        ));

        return ListingDiscoveryResult::success($candidates, $sources);
    }

    private function systemInstruction(): string
    {
        return 'You discover verified Indonesian marketplace listings for one '
            .'canonical Neomatra Product. Use Google Search grounding. Search only '
            .'Tokopedia, Shopee, OLX, Facebook Marketplace, and Carousell. Return '
            .'direct listing candidates only. Never fabricate title, integer IDR '
            .'price, condition, seller, location, stock, or URL. Use unknown for an '
            .'unverifiable condition and null for other optional facts. Reject search '
            .'pages, category pages, manufacturer pages, blogs, reviews, wanted posts, '
            .'unrelated accessories, services, and full PCs when the target is a '
            .'standalone component. Never create or propose a new Product, Alias, or Category.';
    }

    private function prompt(
        Product $product,
        ?int $maximumCandidates,
    ): string {
        $maximum = max(
            1,
            $maximumCandidates ?? (int) config(
                'listing-discovery.max_new_listings_per_product',
                3,
            ),
        );
        $region = trim((string) config(
            'listing-discovery.region',
            'Indonesia',
        ));
        $identity = [
            'Product ID' => $product->id,
            'Name' => $product->name,
            'Category' => $product->category?->name,
            'Brand' => $product->brand,
            'Model' => $product->model,
            'Variant' => $product->variant,
            'SKU' => $product->sku_or_part_number,
        ];
        $lines = [];

        foreach ($identity as $label => $value) {
            $lines[] = $label.': '.mb_substr(
                trim((string) ($value ?? '-')),
                0,
                300,
                'UTF-8',
            );
        }

        return "Canonical Product:\n".implode("\n", $lines)
            ."\nRegion: {$region}"
            ."\nSearch all five permitted marketplaces in one bounded request."
            ."\nReturn at most {$maximum} candidates total as JSON: "
            .'{"candidates":[{"platform":"olx","external_listing_id":null,'
            .'"title":"...","description":null,"price":1234567,'
            .'"currency":"IDR","condition":"unknown","seller_name":null,'
            .'"seller_type":"unknown","location":null,"product_url":"https://...",'
            .'"image_url":null,"stock_status":"unknown","price_type":"full_price",'
            .'"warranty":null,"source_published_at":null}]}';
    }

    private function normalizedModel(string $model): string
    {
        return str_starts_with($model, 'models/')
            ? substr($model, strlen('models/'))
            : $model;
    }

    private function responseText(Response $response): ?string
    {
        $parts = $response->json('candidates.0.content.parts');

        if (! is_array($parts)) {
            return null;
        }

        $text = collect($parts)
            ->pluck('text')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->implode('');
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private function jsonText(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text)
                ?? $text;
        }

        return trim($text);
    }

    /** @return list<array{title:string,url:string}> */
    private function groundingSources(Response $response): array
    {
        $chunks = $response->json(
            'candidates.0.groundingMetadata.groundingChunks',
        );

        if (! is_array($chunks)) {
            return [];
        }

        $sources = [];

        foreach ($chunks as $chunk) {
            $url = is_array($chunk) ? data_get($chunk, 'web.uri') : null;
            $title = is_array($chunk) ? data_get($chunk, 'web.title') : null;

            if (! is_string($url)
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                continue;
            }

            $sources[] = [
                'title' => is_string($title) && trim($title) !== ''
                    ? mb_substr(trim($title), 0, 255, 'UTF-8')
                    : 'Grounded source',
                'url' => mb_substr($url, 0, 2048, 'UTF-8'),
            ];

            if (count($sources) >= 10) {
                break;
            }
        }

        return $sources;
    }
}
