<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\MarketplaceConnector;
use App\Data\IntegrationFailure;
use App\Data\Marketplace\MarketplaceFetchPage;
use App\Data\Marketplace\MarketplaceListingData;
use App\Data\Marketplace\MarketplaceSyncRequest;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class OlxMarketplaceConnector implements MarketplaceConnector
{
    /** @var array<string, mixed> */
    private array $diagnostics = [];

    public function key(): string
    {
        return 'olx-public-web';
    }

    public function supports(string $platformSlug): bool
    {
        return strtolower(trim($platformSlug)) === 'olx';
    }

    public function providesFullSnapshot(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function probe(): array
    {
        $result = $this->fetch(new MarketplaceSyncRequest(
            'olx',
            null,
            max(1, min(50, (int) config(
                'marketplace.olx.max_listings',
                20,
            ))),
        ));

        return [
            ...$this->diagnostics,
            'parsed_records' => count($result->listings),
            'rejected' => $result->skipped,
            'failure_category' => $result->failure?->category,
            'reason' => $result->failure?->safeMessage,
            'upstream_code' => $result->failure?->upstreamCode,
        ];
    }

    public function fetch(MarketplaceSyncRequest $request): MarketplaceFetchPage
    {
        $this->diagnostics = [
            'platform' => 'OLX',
            'url' => null,
            'http_status' => null,
            'content_type' => null,
            'body_size' => 0,
            'redirect_target' => null,
            'final_url' => null,
            'primary_ip' => null,
            'http_version' => null,
            'listing_urls' => 0,
            'detail_pages_parsed' => 0,
        ];

        if (! $this->supports($request->platformSlug)) {
            return $this->failure(
                'unsupported_platform',
                'Connector OLX hanya mendukung platform OLX.',
            );
        }

        if ($request->cursor !== null) {
            return $this->failure(
                'page_limit_reached',
                'Connector OLX saat ini hanya mendukung satu halaman pencarian.',
            );
        }

        $baseUrl = rtrim((string) config(
            'marketplace.olx.base_url',
            'https://www.olx.co.id',
        ), '/');
        $searchTerm = $this->searchTerm();

        if ($searchTerm === null || ! $this->isAllowedUrl($baseUrl, $baseUrl)) {
            return $this->failure(
                'invalid_configuration',
                'Konfigurasi connector OLX tidak valid.',
            );
        }

        $searchUrl = $baseUrl.'/items/q-'.Str::slug($searchTerm);
        $this->diagnostics['url'] = $searchUrl;
        $searchResponse = $this->request($searchUrl, 'search');

        if ($searchResponse instanceof IntegrationFailure) {
            return MarketplaceFetchPage::failed($searchResponse);
        }

        $urls = $this->listingUrls($searchResponse->body(), $baseUrl);
        $this->diagnostics['listing_urls'] = count($urls);
        Log::info('OLX parser stage completed.', [
            'stage' => 'search_listing_discovery',
            'requested_url' => $searchUrl,
            'listing_urls_discovered' => count($urls),
            'body_size' => strlen($searchResponse->body()),
        ]);

        if ($urls === []) {
            return $this->isEmptySearchPage($searchResponse->body())
                ? MarketplaceFetchPage::success([], null)
                : $this->failure(
                    'parse_failure',
                    'Struktur halaman pencarian OLX belum dapat diproses dengan aman.',
                );
        }

        $limit = min(
            max(1, $request->pageSize),
            max(1, min(50, (int) config('marketplace.olx.max_listings', 20))),
        );
        $listings = [];
        $skipped = 0;

        foreach (array_slice($urls, 0, $limit) as $url) {
            $response = $this->request($url, 'detail');

            if ($response instanceof IntegrationFailure) {
                $skipped++;

                continue;
            }

            $listing = $this->parseListing(
                $response->body(),
                $url,
                $searchTerm,
                $baseUrl,
            );

            if ($listing === null) {
                $skipped++;
                Log::warning('OLX detail parser rejected a page.', [
                    'stage' => 'detail_parse',
                    'requested_url' => $url,
                    'body_size' => strlen($response->body()),
                ]);

                continue;
            }

            $listings[] = $listing;
            $this->diagnostics['detail_pages_parsed']++;
        }

        Log::info('OLX detail parser batch completed.', [
            'stage' => 'detail_parse_summary',
            'listing_urls_discovered' => count($urls),
            'detail_pages_parsed' => count($listings),
            'rejected' => $skipped,
        ]);

        if ($listings === []) {
            return $this->failure(
                'parse_failure',
                'Listing OLX ditemukan, tetapi tidak ada record valid yang dapat dinormalisasi.',
            );
        }

        return MarketplaceFetchPage::success($listings, null, $skipped);
    }

    /** @return list<string> */
    private function listingUrls(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);

        if ($xpath === null) {
            return [];
        }

        $urls = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $url = $this->listingUrl(
                $anchor->getAttribute('href'),
                $baseUrl,
            );

            if ($url !== null) {
                $urls[$url] = $url;
            }
        }

        $decodedHtml = str_replace('\\/', '/', html_entity_decode(
            $html,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));

        if (preg_match_all(
            '~(?:https?://[^/\s"\'<>]+)?/item/[^\s"\'<>?#]+(?:-iid-\d+)?~ui',
            $decodedHtml,
            $matches,
        ) > 0) {
            foreach ($matches[0] as $candidate) {
                $url = $this->listingUrl($candidate, $baseUrl);

                if ($url !== null) {
                    $urls[$url] = $url;
                }
            }
        }

        return array_values($urls);
    }

    private function parseListing(
        string $html,
        string $url,
        string $searchTerm,
        string $baseUrl,
    ): ?MarketplaceListingData {
        $xpath = $this->xpath($html);

        if ($xpath === null) {
            return null;
        }

        $schema = $this->schemaProduct($xpath) ?? [];
        $offers = $schema['offers'] ?? [];

        if (is_array($offers) && array_is_list($offers)) {
            $offers = $offers[0] ?? [];
        }

        $offers = is_array($offers) ? $offers : [];
        $title = $this->firstString([
            $schema['name'] ?? null,
            $this->meta($xpath, 'property', 'og:title'),
            $this->nodeText($xpath, '//h1[1]'),
        ]);
        $description = $this->firstString([
            $schema['description'] ?? null,
            $this->meta($xpath, 'property', 'og:description'),
            $this->meta($xpath, 'name', 'description'),
        ]);
        $price = $this->price($this->firstScalarString([
            $offers['price'] ?? null,
            $this->meta($xpath, 'property', 'product:price:amount'),
            $this->nodeText($xpath, '//*[@data-aut-id="itemPrice"][1]'),
        ]));
        $currency = strtoupper((string) ($offers['priceCurrency']
            ?? $this->meta($xpath, 'property', 'product:price:currency')
            ?? 'IDR'));

        if ($title === null
            || $price === null
            || $currency !== 'IDR'
            || $this->isRejectedAdvertisement($title)) {
            return null;
        }

        $pageText = mb_strtolower(trim($title.' '.($description ?? '')), 'UTF-8');

        if (preg_match('/\b(cicilan|angsuran|uang\s*muka|dp\s+mulai)\b/u', $pageText) === 1) {
            return null;
        }

        $image = $schema['image'] ?? null;

        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $imageUrl = $this->firstString([
            $image,
            $this->meta($xpath, 'property', 'og:image'),
        ]);
        $imageUrl = $imageUrl === null
            ? null
            : $this->absoluteUrl($imageUrl, $baseUrl, false);
        $condition = $this->condition($this->firstString([
            $schema['itemCondition'] ?? null,
            $this->nodeText($xpath, '//*[@data-aut-id="itemCondition"][1]'),
            $pageText,
        ]));
        $seller = $schema['seller'] ?? ($offers['seller'] ?? null);
        $sellerName = is_array($seller)
            ? $this->firstString([$seller['name'] ?? null])
            : null;
        $sellerName ??= $this->nodeText(
            $xpath,
            '//*[@data-aut-id="itemSellerName" or @data-aut-id="sellerName"][1]',
        );
        $location = $this->firstString([
            $this->nodeText($xpath, '//*[@data-aut-id="item-location"][1]'),
            $this->nodeText($xpath, '//*[@data-aut-id="itemLocation"][1]'),
        ]);
        $availability = mb_strtolower((string) ($offers['availability'] ?? ''), 'UTF-8');
        $stock = match (true) {
            str_contains($availability, 'instock') => 'in_stock',
            str_contains($availability, 'outofstock'),
            str_contains($availability, 'soldout') => 'out_of_stock',
            default => 'unknown',
        };
        $externalId = preg_match('/-iid-(\d+)\b/i', $url, $match) === 1
            ? $match[1]
            : null;
        $published = $this->firstString([
            $schema['datePosted'] ?? null,
            $schema['datePublished'] ?? null,
            $this->meta($xpath, 'property', 'article:published_time'),
        ]);
        $payload = [
            'external_listing_id' => $externalId,
            'title' => $title,
            'description' => $description,
            'price' => $price,
            'currency' => 'IDR',
            'condition' => $condition,
            'seller_name' => $sellerName,
            'seller_type' => 'unknown',
            'location' => $location,
            'product_url' => $url,
            'image_url' => $imageUrl,
            'stock_status' => $stock,
            'price_type' => 'full_price',
            'shipping_cost' => null,
            'service_fee' => null,
            'inspection_cost' => null,
            'repair_estimate' => null,
            'warranty' => null,
            'source_published_at' => $published,
            'olx_search_term' => $searchTerm,
            'olx_extraction' => $schema === [] ? 'html' : 'json_ld',
        ];

        return MarketplaceListingData::fromArray('olx', $payload);
    }

    private function request(
        string $url,
        string $stage,
    ): Response|IntegrationFailure {
        $transfer = [
            'final_url' => $url,
            'transfer_time' => null,
            'handler_error' => null,
            'primary_ip' => null,
            'http_version' => null,
        ];
        Log::info('OLX HTTP request started.', [
            'stage' => $stage,
            'method' => 'GET',
            'requested_url' => $url,
        ]);

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config(
                    'marketplace.olx.user_agent',
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                        .'AppleWebKit/537.36 (KHTML, like Gecko) '
                        .'Chrome/151.0.0.0 Safari/537.36',
                ),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,'
                    .'image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ])
                ->withOptions([
                    'allow_redirects' => [
                        'max' => 5,
                        'strict' => false,
                        'referer' => true,
                        'track_redirects' => true,
                    ],
                    'on_stats' => static function (TransferStats $stats) use (
                        &$transfer,
                    ): void {
                        $transfer['final_url'] = (string) $stats->getEffectiveUri();
                        $transfer['transfer_time'] = $stats->getTransferTime();
                        $transfer['handler_error'] = $stats->getHandlerErrorData();
                        $handlerStats = $stats->getHandlerStats();
                        $transfer['primary_ip'] = $handlerStats['primary_ip'] ?? null;
                        $transfer['http_version'] = $handlerStats['http_version'] ?? null;
                    },
                ])
                ->timeout(max(1, min(30, (int) config(
                    'marketplace.olx.timeout',
                    12,
                ))))
                ->retry(
                    1 + max(0, min(2, (int) config(
                        'marketplace.olx.retries',
                        1,
                    ))),
                    max(0, min(2000, (int) config(
                        'marketplace.olx.retry_delay_ms',
                        250,
                    ))),
                    throw: false,
                )
                ->get($url);
        } catch (ConnectionException $exception) {
            $failure = $this->connectionFailure($exception);
            Log::error('OLX HTTP connection failed.', [
                'stage' => $stage,
                'method' => 'GET',
                'requested_url' => $url,
                'final_url' => $transfer['final_url'],
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'handler_error' => $transfer['handler_error'],
                'primary_ip' => $transfer['primary_ip'],
                'http_version' => $transfer['http_version'],
                'curl_cainfo' => ini_get('curl.cainfo') ?: null,
                'openssl_cafile' => ini_get('openssl.cafile') ?: null,
            ]);

            return $failure;
        } catch (Throwable $exception) {
            Log::error('OLX HTTP connector failed.', [
                'stage' => $stage,
                'method' => 'GET',
                'requested_url' => $url,
                'final_url' => $transfer['final_url'],
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->integrationFailure(
                'connector_failure',
                'Connector OLX gagal memproses respons upstream.',
            );
        }

        $bodySize = strlen($response->body());
        $contentType = $response->header('Content-Type');
        $redirects = $response->header('X-Guzzle-Redirect-History');
        $redirectTarget = is_array($redirects)
            ? end($redirects) ?: null
            : ($redirects ?: null);
        $finalUrl = is_string($transfer['final_url'])
            ? $transfer['final_url']
            : $url;
        Log::info('OLX HTTP response received.', [
            'stage' => $stage,
            'method' => 'GET',
            'requested_url' => $url,
            'http_status' => $response->status(),
            'redirect_target' => $redirectTarget,
            'final_url' => $finalUrl,
            'content_type' => $contentType,
            'body_size' => $bodySize,
            'transfer_time_seconds' => $transfer['transfer_time'],
            'primary_ip' => $transfer['primary_ip'],
            'http_version' => $transfer['http_version'],
        ]);

        if ($stage === 'search') {
            $this->diagnostics['http_status'] = $response->status();
            $this->diagnostics['content_type'] = $contentType;
            $this->diagnostics['body_size'] = $bodySize;
            $this->diagnostics['redirect_target'] = $redirectTarget;
            $this->diagnostics['final_url'] = $finalUrl;
            $this->diagnostics['primary_ip'] = $transfer['primary_ip'];
            $this->diagnostics['http_version'] = $transfer['http_version'];
        }

        if (! $this->isAllowedUrl($finalUrl, (string) config(
            'marketplace.olx.base_url',
            'https://www.olx.co.id',
        ))) {
            return $this->integrationFailure(
                'unsafe_redirect',
                'OLX mengarahkan request ke host yang tidak diizinkan.',
                $response->status(),
            );
        }

        if ($response->successful()) {
            if ($bodySize === 0) {
                return $this->integrationFailure(
                    'empty_response',
                    'OLX mengembalikan respons kosong.',
                    $response->status(),
                );
            }

            return $response;
        }

        $category = match (true) {
            in_array($response->status(), [301, 302, 303, 307, 308], true) => 'redirect_unresolved',
            $response->status() === 429 => 'rate_limited',
            in_array($response->status(), [401, 403], true) => 'access_denied',
            $response->status() === 404 => 'not_found',
            $response->serverError() => 'upstream_error',
            default => 'http_error',
        };
        $message = match ($category) {
            'redirect_unresolved' => 'Redirect OLX tidak dapat diselesaikan.',
            'rate_limited' => 'OLX membatasi request untuk sementara.',
            'access_denied' => 'OLX menolak request HTTP dari server aplikasi.',
            'not_found' => 'Halaman OLX yang diminta tidak ditemukan.',
            'upstream_error' => 'Server OLX sedang mengalami gangguan.',
            default => 'OLX mengembalikan respons HTTP yang tidak dapat digunakan.',
        };

        return $this->integrationFailure(
            $category,
            $message,
            $response->status(),
        );
    }

    private function connectionFailure(
        ConnectionException $exception,
    ): IntegrationFailure {
        $message = $exception->getMessage();
        $curlCode = preg_match('/cURL error\s+(\d+)/i', $message, $match) === 1
            ? (int) $match[1]
            : null;
        [$category, $safeMessage] = match ($curlCode) {
            6 => ['dns_failure', 'DNS OLX tidak dapat di-resolve oleh PHP.'],
            7 => ['connection_refused', 'Koneksi TCP ke OLX gagal dibuat.'],
            28 => ['timeout', 'Request OLX melewati batas waktu.'],
            35, 51, 58, 59, 60, 77, 80, 82, 83, 90 => [
                'tls_failure',
                'Validasi TLS/SSL OLX gagal pada runtime PHP.',
            ],
            52 => ['empty_response', 'OLX menutup koneksi tanpa respons.'],
            55, 56 => ['connection_reset', 'Koneksi OLX terputus saat transfer data.'],
            92 => ['http2_reset', 'Stream HTTP/2 OLX di-reset oleh server upstream.'],
            default => [
                'connection_failure',
                'OLX tidak dapat dihubungi dari runtime PHP.',
            ],
        };

        return $this->integrationFailure(
            $category,
            $safeMessage,
            null,
            $curlCode === null ? null : 'curl_'.$curlCode,
        );
    }

    private function xpath(string $html): ?DOMXPath
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? new DOMXPath($document) : null;
    }

    /** @return array<string, mixed>|null */
    private function schemaProduct(DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $decoded = json_decode(trim((string) $script->textContent), true);

            if (! is_array($decoded)) {
                continue;
            }

            $product = $this->findSchemaProduct($decoded);

            if ($product !== null) {
                return $product;
            }
        }

        return null;
    }

    /** @param array<mixed> $value @return array<string, mixed>|null */
    private function findSchemaProduct(array $value): ?array
    {
        $type = $value['@type'] ?? null;
        $types = is_array($type) ? $type : [$type];

        if (in_array('Product', $types, true)) {
            return $value;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $product = $this->findSchemaProduct($child);

                if ($product !== null) {
                    return $product;
                }
            }
        }

        return null;
    }

    private function meta(DOMXPath $xpath, string $attribute, string $value): ?string
    {
        $query = sprintf(
            '//meta[translate(@%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content',
            $attribute,
            strtolower($value),
        );

        return $this->nodeText($xpath, $query);
    }

    private function nodeText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof DOMNode
            ? $this->firstString([$node->nodeValue])
            : null;
    }

    /** @param list<mixed> $values */
    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
            }
        }

        return null;
    }

    /** @param list<mixed> $values */
    private function firstScalarString(array $values): ?string
    {
        foreach ($values as $value) {
            if ((is_string($value) || is_int($value) || is_float($value))
                && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function price(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        $price = filter_var($digits, FILTER_VALIDATE_INT);

        return is_int($price) && $price > 0 ? $price : null;
    }

    private function condition(?string $value): string
    {
        $value = mb_strtolower((string) $value, 'UTF-8');

        return match (true) {
            str_contains($value, 'used'),
            str_contains($value, 'bekas') => 'used',
            str_contains($value, 'new'),
            str_contains($value, 'baru') => 'new',
            default => 'unknown',
        };
    }

    private function absoluteUrl(
        string $url,
        string $baseUrl,
        bool $requireOlxHost = true,
    ): ?string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = rtrim($baseUrl, '/').$url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return ! $requireOlxHost || $this->isAllowedUrl($url, $baseUrl)
            ? $url
            : null;
    }

    private function listingUrl(string $url, string $baseUrl): ?string
    {
        $url = $this->absoluteUrl($url, $baseUrl);

        if ($url === null
            || preg_match('~/item/[^?#]+(?:-iid-\d+)?~i', $url) !== 1) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '') {
            return null;
        }

        return $scheme.'://'.$host
            .(is_int($port) ? ':'.$port : '')
            .$path;
    }

    private function isAllowedUrl(string $url, string $baseUrl): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $olxHost = preg_replace('/^www\./', '', $baseHost) ?: $baseHost;

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && $olxHost !== ''
            && ($host === $olxHost || str_ends_with($host, '.'.$olxHost));
    }

    private function isRejectedAdvertisement(string $title): bool
    {
        return preg_match(
            '/\b(jasa|service|servis|repair|sewa|rental|dicari|wanted|wtb|terima\s+beli|box\s*only|dus\s*(?:kosong|saja)|empty\s*box)\b/ui',
            $title,
        ) === 1;
    }

    private function isEmptySearchPage(string $html): bool
    {
        return preg_match(
            '/(?:0\s*iklan|tidak ada iklan|hasil tidak ditemukan|no results)/ui',
            strip_tags($html),
        ) === 1;
    }

    private function searchTerm(): ?string
    {
        $terms = config('marketplace.olx.search_terms', []);

        if (! is_array($terms)) {
            return null;
        }

        foreach ($terms as $term) {
            if (is_string($term) && trim($term) !== '') {
                return trim($term);
            }
        }

        return null;
    }

    private function failure(string $category, string $message): MarketplaceFetchPage
    {
        return MarketplaceFetchPage::failed(
            $this->integrationFailure($category, $message),
        );
    }

    private function integrationFailure(
        string $category,
        string $message,
        ?int $status = null,
        ?string $upstreamCode = null,
    ): IntegrationFailure {
        return new IntegrationFailure(
            $this->key(),
            'marketplace_fetch',
            $category,
            $message,
            null,
            $status,
            $upstreamCode,
        );
    }
}
