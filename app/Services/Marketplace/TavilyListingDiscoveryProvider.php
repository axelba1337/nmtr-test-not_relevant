<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Data\Marketplace\ListingDiscoveryResult;
use App\Data\Marketplace\MarketplaceListingProbeResult;
use App\Models\Listing;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

final class TavilyListingDiscoveryProvider implements ListingDiscoveryProvider
{
    /** @var list<string> */
    private const SEARCH_DOMAINS = [
        'tokopedia.com',
        'shopee.co.id',
        'olx.co.id',
        'facebook.com',
        'id.carousell.com',
        'carousell.com',
    ];

    /** @var array<string, list<string>> */
    private const PLATFORM_DOMAINS = [
        'tokopedia' => ['tokopedia.com'],
        'shopee' => ['shopee.co.id'],
        'olx' => ['olx.co.id'],
        'facebook' => ['facebook.com'],
        'carousell' => ['id.carousell.com', 'carousell.com'],
    ];

    public function __construct(
        private readonly TavilyHttpClient $http,
        private readonly MarketplaceCsvImportService $csv,
        private readonly MarketplaceListingHttpProbe $probe,
    ) {}

    public function discover(
        Product $product,
        ?int $maximumCandidates = null,
    ): ListingDiscoveryResult {
        $product->loadMissing([
            'category:id,name,slug',
            'aliases:id,product_id,alias',
        ]);
        $maximum = $this->candidateMaximum($maximumCandidates);
        $diagnostics = $this->emptyDiagnostics();
        $candidates = [];
        $sources = [];
        $seenUrls = [];
        $platformCandidateCounts = [];
        $extractRemaining = $this->extractMaximum();
        $partialFailureCategory = null;
        $partialFailureMessage = null;
        $query = $this->query($product);
        $primary = $this->search($query, self::SEARCH_DOMAINS, $diagnostics);

        if ($primary['failure_category'] !== null) {
            return ListingDiscoveryResult::failed(
                $primary['failure_category'],
                $primary['failure_message']
                    ?? 'Tavily Search could not be completed.',
                $diagnostics,
            );
        }

        $diagnostics['primary_searches']++;
        $this->processResults(
            $product,
            $primary['results'],
            $maximum,
            $candidates,
            $sources,
            $seenUrls,
            $platformCandidateCounts,
            $extractRemaining,
            $diagnostics,
            $partialFailureCategory,
            $partialFailureMessage,
        );

        if (count($candidates) < $maximum
            && (bool) config(
                'listing-discovery.platform_fallback.enabled',
                true,
            )) {
            $fallbackMaximum = min(5, max(0, (int) config(
                'listing-discovery.platform_fallback.max_searches_per_product',
            )));
            $candidatePlatforms = array_values(array_unique(array_column(
                $candidates,
                'platform',
            )));
            $missingPlatforms = array_values(array_diff(
                array_keys(self::PLATFORM_DOMAINS),
                $candidatePlatforms,
            ));

            foreach (array_slice($missingPlatforms, 0, $fallbackMaximum) as $platform) {
                if (count($candidates) >= $maximum) {
                    break;
                }

                $diagnostics['fallback_searches']++;
                $fallback = $this->search(
                    $query.' site:'.self::PLATFORM_DOMAINS[$platform][0],
                    self::PLATFORM_DOMAINS[$platform],
                    $diagnostics,
                );

                if ($fallback['failure_category'] !== null) {
                    $partialFailureCategory ??= $fallback['failure_category'];
                    $partialFailureMessage ??= $fallback['failure_message'];

                    continue;
                }

                $this->processResults(
                    $product,
                    $fallback['results'],
                    $maximum,
                    $candidates,
                    $sources,
                    $seenUrls,
                    $platformCandidateCounts,
                    $extractRemaining,
                    $diagnostics,
                    $partialFailureCategory,
                    $partialFailureMessage,
                );
            }
        }

        $diagnostics['valid_candidates'] = count($candidates);

        if ($candidates === [] && $partialFailureCategory !== null) {
            return ListingDiscoveryResult::failed(
                $partialFailureCategory,
                $partialFailureMessage
                    ?? 'Listing discovery could not complete its fallback request.',
                $diagnostics,
            );
        }

        return ListingDiscoveryResult::success(
            array_slice($candidates, 0, $maximum),
            $sources,
            $diagnostics,
            $partialFailureCategory,
            $partialFailureMessage,
        );
    }

    private function query(Product $product): string
    {
        $parts = [];

        foreach ([
            $product->sku_or_part_number,
            trim((string) $product->brand.' '.(string) $product->model),
            $product->variant,
            $product->name,
        ] as $value) {
            $value = trim((string) $value);

            if ($value === '' || collect($parts)->contains(
                fn (string $part): bool => $this->compact($part) === $this->compact($value),
            )) {
                continue;
            }

            $parts[] = $value;
        }

        if ($parts === []) {
            $parts[] = trim((string) $product->category?->name);
        }

        return mb_substr(
            trim(implode(' ', $parts).' Indonesia'),
            0,
            400,
            'UTF-8',
        );
    }

    /**
     * @param  list<string>  $domains
     * @param  array<string, mixed>  $diagnostics
     * @return array{results:list<array<string,mixed>>,failure_category:?string,failure_message:?string}
     */
    private function search(
        string $query,
        array $domains,
        array &$diagnostics,
    ): array {
        $cacheKey = 'listing-discovery:tavily-search:v2:'.hash(
            'sha256',
            json_encode([
                'query' => $query,
                'region' => (string) config('listing-discovery.region', 'Indonesia'),
                'country' => $this->country(),
                'depth' => $this->searchDepth(),
                'maximum' => $this->searchMaximum(),
                'domains' => $domains,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $cacheHours = min(168, max(0, (int) config(
            'listing-discovery.search_cache_hours',
        )));
        $cached = $cacheHours > 0 ? Cache::get($cacheKey) : null;

        if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
            $diagnostics['cache_hits']++;

            return [
                'results' => $cached['results'],
                'failure_category' => null,
                'failure_message' => null,
            ];
        }

        $response = $this->http->search([
            'query' => $query,
            'search_depth' => $this->searchDepth(),
            'topic' => 'general',
            'country' => $this->country(),
            'max_results' => $this->searchMaximum(),
            'include_answer' => false,
            'include_raw_content' => false,
            'include_domains' => $domains,
        ]);

        if (! $response->succeeded()) {
            return [
                'results' => [],
                'failure_category' => $response->failure?->category
                    ?? 'provider_error',
                'failure_message' => $response->failure?->safeMessage
                    ?? 'Tavily Search could not be completed.',
            ];
        }

        $results = $this->safeSearchResults(
            $response->response?->json('results'),
        );

        if ($cacheHours > 0) {
            Cache::put($cacheKey, ['results' => $results], now()->addHours($cacheHours));
        }

        return [
            'results' => $results,
            'failure_category' => null,
            'failure_message' => null,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array{title:string,url:string}>  $sources
     * @param  array<string, bool>  $seenUrls
     * @param  array<string, int>  $platformCandidateCounts
     * @param  array<string, mixed>  $diagnostics
     */
    private function processResults(
        Product $product,
        array $results,
        int $maximum,
        array &$candidates,
        array &$sources,
        array &$seenUrls,
        array &$platformCandidateCounts,
        int &$extractRemaining,
        array &$diagnostics,
        ?string &$partialFailureCategory,
        ?string &$partialFailureMessage,
    ): void {
        $promising = [];
        $diagnostics['search_results'] += count($results);

        foreach ($results as $result) {
            $originalUrl = trim((string) ($result['url'] ?? ''));
            $platform = $this->csv->platformSlugForUrlHost($originalUrl);

            if ($platform === null) {
                $this->reject($diagnostics, 'unsupported_url');

                continue;
            }

            $diagnostics['supported_domain_results']++;
            $normalizedUrl = $this->csv->normalizeUrl($originalUrl);
            $title = $this->cleanText($result['title'] ?? null, 255);
            $content = $this->cleanText($result['content'] ?? null, 4000) ?? '';

            if ($normalizedUrl === null
                || strtolower((string) parse_url($originalUrl, PHP_URL_SCHEME)) !== 'https'
                || $title === null
                || ! $this->isDirectListingUrl($platform, $normalizedUrl)) {
                $this->reject($diagnostics, 'not_direct_listing');

                continue;
            }

            if (isset($seenUrls[$normalizedUrl])) {
                $this->reject($diagnostics, 'duplicate_url');

                continue;
            }

            $seenUrls[$normalizedUrl] = true;
            $diagnostics['direct_listing_urls']++;
            $compatibility = $this->compatibility($product, $title, $content);

            if (! $compatibility['matches']) {
                $this->reject($diagnostics, $compatibility['reason']);

                continue;
            }

            $diagnostics['product_matches']++;
            $promising[] = [
                'platform' => $platform,
                'title' => $title,
                'url' => $normalizedUrl,
                'content' => $content,
                'score' => is_numeric($result['score'] ?? null)
                    ? (float) $result['score']
                    : 0.0,
            ];
        }

        $unresolved = [];

        foreach ($this->diverseOrder($promising) as $result) {
            if (count($candidates) >= $maximum) {
                break;
            }

            $platform = $result['platform'];

            if (($platformCandidateCounts[$platform] ?? 0) >= $this->perPlatformMaximum($maximum)) {
                continue;
            }

            $probe = $this->probe->probe($result['url']);

            if ($probe->attempted) {
                $diagnostics['http_probe_attempts']++;
            }

            if ($probe->succeeded) {
                $diagnostics['http_probe_success']++;
            }

            $priceEvidence = $probe->price !== null
                ? ['price' => $probe->price, 'reason' => null]
                : $this->priceEvidence([$result['title'], $result['content']]);

            if ($priceEvidence['price'] !== null) {
                $candidate = $this->candidate(
                    $product,
                    $result,
                    $probe,
                    $priceEvidence['price'],
                    null,
                );
                $candidates[] = $candidate;
                $sources[] = [
                    'title' => $result['title'],
                    'url' => $result['url'],
                ];
                $platformCandidateCounts[$platform] = ($platformCandidateCounts[$platform] ?? 0) + 1;
                $diagnostics['search_evidence_candidates']++;

                continue;
            }

            if (($priceEvidence['reason'] ?? null) === 'price_ambiguous') {
                $this->reject($diagnostics, 'price_ambiguous');

                continue;
            }

            $unresolved[] = [
                'result' => $result,
                'probe' => $probe,
                'price_reason' => $priceEvidence['reason'] ?? 'price_missing',
            ];
        }

        if ($unresolved === []) {
            return;
        }

        $extractEnabled = (bool) config(
            'listing-discovery.tavily.extract_enabled',
            true,
        );
        $extractable = array_slice($unresolved, 0, max(0, $extractRemaining));

        if (! $extractEnabled || $extractable === []) {
            foreach ($unresolved as $item) {
                $this->reject($diagnostics, $item['price_reason']);
            }

            return;
        }

        $extractRemaining -= count($extractable);
        $diagnostics['extract_attempts'] += count($extractable);
        $extract = $this->http->extract([
            'urls' => array_map(
                static fn (array $item): string => $item['result']['url'],
                $extractable,
            ),
            'extract_depth' => $this->extractDepth(),
        ]);

        if (! $extract->succeeded()) {
            $partialFailureCategory ??= $extract->failure?->category
                ?? 'extract_unavailable';
            $partialFailureMessage ??= $extract->failure?->safeMessage
                ?? 'Tavily Extract could not be completed.';

            foreach ($unresolved as $item) {
                $this->reject($diagnostics, 'extract_unavailable');
            }

            return;
        }

        $extracted = $this->extractedContent(
            $extract->response?->json('results'),
        );

        foreach ($extractable as $item) {
            if (count($candidates) >= $maximum) {
                break;
            }

            $result = $item['result'];
            $rawContent = $extracted[$result['url']] ?? null;

            if (! is_string($rawContent) || trim($rawContent) === '') {
                $this->reject($diagnostics, 'extract_unavailable');

                continue;
            }

            $diagnostics['extract_success']++;
            $priceEvidence = $this->priceEvidence([$rawContent]);

            if ($priceEvidence['price'] === null) {
                $this->reject(
                    $diagnostics,
                    $priceEvidence['reason'] ?? 'price_missing',
                );

                continue;
            }

            $platform = $result['platform'];

            if (($platformCandidateCounts[$platform] ?? 0) >= $this->perPlatformMaximum($maximum)) {
                continue;
            }

            $candidates[] = $this->candidate(
                $product,
                $result,
                $item['probe'],
                $priceEvidence['price'],
                $rawContent,
            );
            $sources[] = [
                'title' => $result['title'],
                'url' => $result['url'],
            ];
            $platformCandidateCounts[$platform] = ($platformCandidateCounts[$platform] ?? 0) + 1;
        }

        foreach (array_slice($unresolved, count($extractable)) as $item) {
            $this->reject($diagnostics, $item['price_reason']);
        }
    }

    /** @return array{matches:bool,reason:string} */
    private function compatibility(
        Product $product,
        string $title,
        string $content,
    ): array {
        $text = mb_strtolower(trim($title.' '.$content), 'UTF-8');
        $unsafeReason = $this->unsafeReason(
            $text,
            (string) $product->category?->slug,
        );

        if ($unsafeReason !== null) {
            return ['matches' => false, 'reason' => $unsafeReason];
        }

        $compactEvidence = $this->compact($title.' '.$content);
        $sku = $this->compact((string) $product->sku_or_part_number);
        $model = $this->compact((string) $product->model);
        $aliases = $product->aliases
            ->pluck('alias')
            ->map(fn (mixed $alias): string => $this->compact((string) $alias))
            ->filter(static fn (string $alias): bool => mb_strlen($alias, 'UTF-8') >= 4)
            ->values()
            ->all();
        $skuMatched = $sku !== '' && str_contains($compactEvidence, $sku);
        $identityFound = $skuMatched
            || ($model !== '' && mb_strlen($model, 'UTF-8') >= 3
                && str_contains($compactEvidence, $model))
            || collect($aliases)->contains(
                static fn (string $alias): bool => str_contains($compactEvidence, $alias),
            );

        if (! $identityFound) {
            $nameTokens = $this->meaningfulTokens((string) $product->name);
            $matchedTokens = array_filter(
                $nameTokens,
                static fn (string $token): bool => preg_match(
                    '/(?<![\pL\pN])'.preg_quote($token, '/').'(?![\pL\pN])/u',
                    $text,
                ) === 1,
            );
            $identityFound = $nameTokens !== []
                && count($matchedTokens) >= max(2, (int) ceil(count($nameTokens) * 0.6));
        }

        if (! $identityFound) {
            return ['matches' => false, 'reason' => 'product_mismatch'];
        }

        if ($skuMatched) {
            return ['matches' => true, 'reason' => ''];
        }

        $productIdentity = mb_strtolower(implode(' ', [
            (string) $product->name,
            (string) $product->model,
            (string) $product->variant,
        ]), 'UTF-8');

        foreach (['ti', 'super', 'xt', 'xtx', 'pro', 'max', 'mini', 'plus', 'ultra'] as $modifier) {
            $listingHas = preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $text) === 1;
            $productHas = preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $productIdentity) === 1;

            if ($listingHas !== $productHas) {
                return ['matches' => false, 'reason' => 'variant_conflict'];
            }
        }

        $productCapacity = $this->capacities($productIdentity);
        $listingCapacity = $this->capacities($text);

        if ($productCapacity !== []
            && $listingCapacity !== []
            && array_intersect($productCapacity, $listingCapacity) === []) {
            return ['matches' => false, 'reason' => 'variant_conflict'];
        }

        return ['matches' => true, 'reason' => ''];
    }

    private function unsafeReason(string $text, string $category): ?string
    {
        if (preg_match(
            '/\b(jasa|service|servis|repair|perbaikan|sewa|rental|dicari|mencari|wanted|wtb|terima\s+beli|review|ulasan|box\s*only|dus\s*(?:saja|kosong)|empty\s*box|spare\s*part|sparepart|bundle|bundling|borongan|casing|case\s*only|cover|kabel|cable|charger|bracket|mount\s*only|stand\s*only|heatsink\s*only|fan\s*only)\b/u',
            $text,
        ) === 1) {
            return 'product_mismatch';
        }

        if (in_array($category, [
            'gpu',
            'cpu',
            'ram',
            'ssd',
            'hdd',
            'motherboard',
            'psu',
            'cpu-cooler',
        ], true) && preg_match(
            '/\b(laptop|notebook|pc\s*(?:gaming|rakitan|desktop|fullset|full\s*set)|komputer\s*(?:rakitan|gaming)|mini\s*pc|all\s*in\s*one)\b/u',
            $text,
        ) === 1) {
            return 'full_pc_mismatch';
        }

        return null;
    }

    private function isDirectListingUrl(string $platform, string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '' || $path === '/'
            || preg_match(
                '~/(?:search|c(?:ategory)?|categories|blog|help|review|reviews|manufacturer|official|shop|mall|feed)(?:/|$)~i',
                $path,
            ) === 1) {
            return false;
        }

        return match ($platform) {
            'olx' => preg_match('#^/item/[A-Za-z0-9._~-]+-iid-?\d{5,}/?$#i', $path) === 1,
            'shopee' => preg_match('~(?:-i\.\d+\.\d+|^/product/\d+/\d+)/?$~i', $path) === 1,
            'facebook' => preg_match('~^/marketplace/item/\d{5,}/?$~', $path) === 1,
            'carousell' => preg_match('#^/p/[A-Za-z0-9][A-Za-z0-9._~-]*-\d{5,}/?$#', $path) === 1,
            'tokopedia' => $this->isTokopediaProductPath($path),
            default => false,
        };
    }

    private function isTokopediaProductPath(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));

        return count($segments) === 2
            && ! in_array(strtolower($segments[0]), [
                'search',
                'p',
                'category',
                'discovery',
                'blog',
                'help',
            ], true);
    }

    /**
     * @param  array{platform:string,title:string,url:string,content:string,score:float}  $result
     * @return array<string, mixed>
     */
    private function candidate(
        Product $product,
        array $result,
        MarketplaceListingProbeResult $probe,
        int $price,
        ?string $extractContent,
    ): array {
        $description = $this->cleanText(
            $probe->description
                ?? ($result['content'] !== '' ? $result['content'] : $extractContent),
            10_000,
        );
        $evidence = trim(implode(' ', array_filter([
            $result['title'],
            $result['content'],
            $probe->title,
            $probe->description,
            $probe->availability,
            $extractContent,
        ])));

        return [
            'platform' => $result['platform'],
            'product_reference' => $product->normalized_key,
            'product_brand' => $product->brand,
            'product_model' => $product->model,
            'product_variant' => $product->variant,
            'product_sku' => $product->sku_or_part_number,
            'external_listing_id' => $this->externalId(
                $result['platform'],
                $result['url'],
            ),
            'title' => $result['title'],
            'description' => $description,
            'price' => $price,
            'currency' => 'IDR',
            'condition' => $this->condition($evidence),
            'seller_name' => null,
            'seller_type' => Listing::SELLER_UNKNOWN,
            'location' => null,
            'product_url' => $result['url'],
            'image_url' => $probe->imageUrl,
            'stock_status' => $this->stockStatus($evidence),
            'price_type' => Listing::PRICE_FULL,
            'warranty' => null,
            'source_published_at' => null,
            'discovery_evidence' => $extractContent !== null
                ? 'tavily_extract'
                : ($probe->price !== null ? 'http_probe' : 'tavily_search'),
        ];
    }

    /** @param list<string> $texts
     * @return array{price:?int,reason:?string}
     */
    private function priceEvidence(array $texts): array
    {
        $text = trim(implode(' ', array_filter($texts)));

        if ($text === '') {
            return ['price' => null, 'reason' => 'price_missing'];
        }

        $prices = $this->prices($text);

        if (count($prices) === 1) {
            return ['price' => $prices[0], 'reason' => null];
        }

        return [
            'price' => null,
            'reason' => count($prices) > 1 || $this->hasPriceSignal($text)
                ? 'price_ambiguous'
                : 'price_missing',
        ];
    }

    /** @return list<int> */
    private function prices(string $text): array
    {
        $prices = [];
        $patterns = [
            '/\brp\.?\s*([0-9]{1,3}(?:[.\s][0-9]{3})+|[0-9]{4,15})(?!\d)/iu',
            '/(?<![\pL\pN])(?:rp\.?\s*)?([0-9]+(?:[,.][0-9]{1,2})?)\s*(?:juta|jt)\b/iu',
            '/(?<![\d.,])([1-9][0-9]{0,2}(?:\.[0-9]{3}){2,4})(?![\d.,])/u',
        ];

        foreach ($patterns as $index => $pattern) {
            preg_match_all(
                $pattern,
                $text,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($matches as $match) {
                $matched = $match[0][0] ?? '';
                $offset = $match[0][1] ?? 0;

                if (! is_string($matched)
                    || ! is_int($offset)
                    || $this->isNonFullPriceContext($text, $offset, strlen($matched))) {
                    continue;
                }

                $numeric = (string) ($match[1][0] ?? '');
                $value = $index === 1
                    ? (int) round((float) str_replace(',', '.', $numeric) * 1_000_000)
                    : (int) preg_replace('/[^0-9]/', '', $numeric);

                if ($value > 0 && $value <= Listing::MAX_MONEY) {
                    $prices[$value] = true;
                }
            }
        }

        return array_map('intval', array_keys($prices));
    }

    private function hasPriceSignal(string $text): bool
    {
        return preg_match(
            '/\brp\.?\s*\d|\d+(?:[,.]\d+)?\s*(?:juta|jt)\b|\b(?:dp|cicilan|angsuran|down\s*payment)\b/iu',
            $text,
        ) === 1;
    }

    private function isNonFullPriceContext(
        string $text,
        int $offset,
        int $length,
    ): bool {
        $context = mb_strtolower(substr(
            $text,
            max(0, $offset - 45),
            $length + 90,
        ), 'UTF-8');

        return preg_match(
            '/\b(dp|down\s*payment|uang\s*muka|cicilan|angsuran|per\s*bulan|mulai\s*dari|starting\s*(?:at|from)|ongkir|shipping|cashback|diskon|discount|potongan|harga\s*normal|harga\s*coret)\b|\/bulan/u',
            $context,
        ) === 1;
    }

    private function condition(string $evidence): string
    {
        $text = mb_strtolower($evidence, 'UTF-8');
        $signals = [];
        $patterns = [
            Listing::CONDITION_OPEN_BOX => '/\b(open[\s-]*box|baru\s+dibuka|hanya\s+dibuka)\b/u',
            Listing::CONDITION_REFURBISHED => '/\b(refurbished|rekondisi|reconditioned)\b/u',
            Listing::CONDITION_EX_DISPLAY => '/\b(ex[\s-]*(?:display|demo)|barang\s+display|pajangan\s+toko|display\s+unit)\b/u',
            Listing::CONDITION_USED => '/\b(second|secondhand|bekas|used|pre[\s-]*owned|pemakaian\s+pribadi)\b/u',
            Listing::CONDITION_NEW => '/\b(baru|sealed|bnib|brand\s+new|unused)\b/u',
        ];

        foreach ($patterns as $condition => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $signals[] = $condition;
            }
        }

        return count(array_unique($signals)) === 1
            ? $signals[0]
            : Listing::CONDITION_UNKNOWN;
    }

    private function stockStatus(string $evidence): string
    {
        $text = mb_strtolower($evidence, 'UTF-8');
        $available = preg_match(
            '/\b(ready(?:\s*stock)?|stok\s+tersedia|in\s*stock|instock|available|tersedia)\b/u',
            $text,
        ) === 1;
        $unavailable = preg_match(
            '/\b(out\s*of\s*stock|outofstock|sold\s*out|terjual|stok\s+habis|habis)\b/u',
            $text,
        ) === 1;

        return match (true) {
            $available && ! $unavailable => Listing::STOCK_IN_STOCK,
            $unavailable && ! $available => Listing::STOCK_OUT_OF_STOCK,
            default => Listing::STOCK_UNKNOWN,
        };
    }

    private function externalId(string $platform, string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $pattern = match ($platform) {
            'olx' => '/iid-?(\d{5,})/i',
            'shopee' => '/(?:-i\.\d+\.|\/product\/\d+\/)(\d+)/i',
            'facebook' => '~/marketplace/item/(\d{5,})~',
            'carousell' => '/-(\d{5,})\/?$/',
            default => null,
        };

        if ($pattern === null || preg_match($pattern, $path, $match) !== 1) {
            return null;
        }

        return mb_substr($match[1], 0, 255, 'UTF-8');
    }

    /** @return array<string, string> */
    private function extractedContent(mixed $results): array
    {
        if (! is_array($results)) {
            return [];
        }

        $content = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $url = $this->csv->normalizeUrl((string) ($result['url'] ?? ''));
            $raw = $result['raw_content'] ?? null;

            if ($url !== null && is_string($raw) && trim($raw) !== '') {
                $content[$url] = $this->cleanText($raw, 20_000) ?? '';
            }
        }

        return $content;
    }

    /** @param list<array{platform:string,title:string,url:string,content:string,score:float}> $results
     * @return list<array{platform:string,title:string,url:string,content:string,score:float}>
     */
    private function diverseOrder(array $results): array
    {
        $groups = [];

        foreach ($results as $result) {
            $groups[$result['platform']][] = $result;
        }

        foreach ($groups as &$group) {
            usort(
                $group,
                static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
            );
        }
        unset($group);

        $ordered = [];

        while ($groups !== []) {
            foreach (array_keys($groups) as $platform) {
                $next = array_shift($groups[$platform]);

                if ($next !== null) {
                    $ordered[] = $next;
                }

                if ($groups[$platform] === []) {
                    unset($groups[$platform]);
                }
            }
        }

        return $ordered;
    }

    /** @return list<array<string,mixed>> */
    private function safeSearchResults(mixed $results): array
    {
        if (! is_array($results)) {
            return [];
        }

        $safe = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $safe[] = [
                'url' => $this->cleanText($result['url'] ?? null, 2048),
                'title' => $this->cleanText($result['title'] ?? null, 255),
                'content' => $this->cleanText($result['content'] ?? null, 4000),
                'score' => is_numeric($result['score'] ?? null)
                    ? (float) $result['score']
                    : 0.0,
            ];
        }

        return $safe;
    }

    /** @return list<string> */
    private function meaningfulTokens(string $value): array
    {
        $tokens = preg_split(
            '/[^\pL\pN]+/u',
            mb_strtolower($value, 'UTF-8'),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $ignored = ['the', 'and', 'with', 'untuk', 'dan', 'product', 'produk'];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 2
                && ! in_array($token, $ignored, true),
        )));
    }

    /** @return list<string> */
    private function capacities(string $text): array
    {
        preg_match_all('/\b(\d+)\s*(gb|tb)\b/u', $text, $matches);
        $capacities = [];

        foreach ($matches[0] ?? [] as $capacity) {
            $capacities[] = preg_replace('/\s+/', '', $capacity) ?? $capacity;
        }

        return array_values(array_unique($capacities));
    }

    /** @param array<string,mixed> $diagnostics */
    private function reject(array &$diagnostics, string $reason): void
    {
        $diagnostics['rejected_candidates']++;
        $diagnostics['rejection_reasons'][$reason] = (
            $diagnostics['rejection_reasons'][$reason] ?? 0
        ) + 1;
    }

    /** @return array<string,mixed> */
    private function emptyDiagnostics(): array
    {
        return [
            'primary_searches' => 0,
            'fallback_searches' => 0,
            'cache_hits' => 0,
            'search_results' => 0,
            'supported_domain_results' => 0,
            'direct_listing_urls' => 0,
            'product_matches' => 0,
            'search_evidence_candidates' => 0,
            'http_probe_attempts' => 0,
            'http_probe_success' => 0,
            'extract_attempts' => 0,
            'extract_success' => 0,
            'valid_candidates' => 0,
            'rejected_candidates' => 0,
            'rejection_reasons' => [],
        ];
    }

    private function compact(string $value): string
    {
        return preg_replace(
            '/[^\pL\pN]+/u',
            '',
            mb_strtolower($value, 'UTF-8'),
        ) ?? '';
    }

    private function cleanText(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace(
            '/\s+/u',
            ' ',
            strip_tags((string) $value),
        ) ?? '');

        return $value === ''
            ? null
            : mb_substr($value, 0, $maximum, 'UTF-8');
    }

    private function candidateMaximum(?int $maximumCandidates): int
    {
        return min(25, max(1, $maximumCandidates ?? (int) config(
            'listing-discovery.max_new_listings_per_product',
            3,
        )));
    }

    private function perPlatformMaximum(int $maximum): int
    {
        return min($maximum, max(1, (int) config(
            'listing-discovery.max_per_platform_per_product',
        )));
    }

    private function searchDepth(): string
    {
        $depth = strtolower(trim((string) config(
            'listing-discovery.tavily.search_depth',
            'basic',
        )));

        return in_array($depth, ['basic', 'advanced'], true)
            ? $depth
            : 'basic';
    }

    private function extractDepth(): string
    {
        $depth = strtolower(trim((string) config(
            'listing-discovery.tavily.extract_depth',
            'basic',
        )));

        return in_array($depth, ['basic', 'advanced'], true)
            ? $depth
            : 'basic';
    }

    private function country(): string
    {
        $country = strtolower(trim((string) config(
            'listing-discovery.tavily.country',
            'indonesia',
        )));

        return $country !== '' ? $country : 'indonesia';
    }

    private function searchMaximum(): int
    {
        return max(1, (int) config(
            'listing-discovery.tavily.search_max_results',
        ));
    }

    private function extractMaximum(): int
    {
        return min(10, max(0, (int) config(
            'listing-discovery.tavily.extract_max_urls_per_product',
            3,
        )));
    }
}
