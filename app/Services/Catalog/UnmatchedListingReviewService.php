<?php

namespace App\Services\Catalog;

use App\Helpers\Normalizer;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Embedding\EntityEmbeddingService;
use App\Services\Search\CategoryAliasResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UnmatchedListingReviewService
{
    public function __construct(
        private readonly CatalogScope $scope,
        private readonly CategoryAliasResolver $categories,
        private readonly EntityEmbeddingService $embeddings,
    ) {}

    /**
     * @return array{
     *     category_id: int|null,
     *     name: string,
     *     brand: string,
     *     model: string,
     *     variant: string,
     *     sku_or_part_number: string,
     *     aliases: list<string>,
     *     raw_metadata: array<string, string>
     * }
     */
    public function suggestions(Listing $listing): array
    {
        $payload = is_array($listing->raw_payload)
            ? $listing->raw_payload
            : [];
        $rawMetadata = [
            'brand' => $this->safeMetadata($payload['product_brand'] ?? null),
            'model' => $this->safeMetadata($payload['product_model'] ?? null),
            'variant' => $this->safeMetadata($payload['product_variant'] ?? null),
            'sku_or_part_number' => $this->safeMetadata($payload['product_sku'] ?? null),
        ];
        $category = $this->categories->resolve(trim(
            $listing->title.' '.(string) $listing->description,
        ))?->category;
        $categoryId = $category !== null && $this->scope->supports($category)
            ? (int) $category->getKey()
            : null;
        $identity = array_values(array_filter([
            $rawMetadata['brand'],
            $rawMetadata['model'],
            $rawMetadata['variant'],
        ]));
        $aliases = [];

        if ($rawMetadata['model'] !== '') {
            $aliases[] = $rawMetadata['model'];

            if ($rawMetadata['brand'] !== '') {
                $aliases[] = trim(
                    $rawMetadata['brand'].' '.$rawMetadata['model'],
                );
            }
        }

        return [
            'category_id' => $categoryId,
            'name' => $rawMetadata['model'] !== ''
                ? implode(' ', $identity)
                : '',
            'brand' => $rawMetadata['brand'],
            'model' => $rawMetadata['model'],
            'variant' => $rawMetadata['variant'],
            'sku_or_part_number' => $rawMetadata['sku_or_part_number'],
            'aliases' => array_values(array_unique($aliases)),
            'raw_metadata' => $rawMetadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{product: Product, embedding_failed: bool}
     */
    public function approveNew(Listing $listing, array $data): array
    {
        $this->assertUnmatched($listing);
        $aliases = $this->parseAliases($data['aliases'] ?? null);
        unset($data['aliases']);
        $data['is_active'] = true;
        $this->assertNoEquivalentProduct($data);

        /** @var Product $product */
        $product = DB::transaction(function () use (
            $listing,
            $data,
            $aliases,
        ): Product {
            $product = Product::query()->create($data);

            foreach ($aliases as $alias) {
                $this->createApprovedAlias($product, $alias);
            }

            $listing->forceFill(['product_id' => $product->getKey()])->save();

            return $product;
        });

        $failed = $this->rebuildNewProductEmbeddings($product);

        return ['product' => $product, 'embedding_failed' => $failed];
    }

    /**
     * @param  list<string>  $aliases
     * @return array{product: Product, embedding_failed: bool}
     */
    public function linkExisting(
        Listing $listing,
        Product $product,
        array $aliases,
    ): array {
        $this->assertUnmatched($listing);
        $product->loadMissing('category.parent.parent');

        if (! $this->scope->supports($product->category)) {
            throw ValidationException::withMessages([
                'product_id' => __('ui.validation.custom.unsupported_product_category'),
            ]);
        }

        /** @var list<ProductAlias> $createdAliases */
        $createdAliases = DB::transaction(function () use (
            $listing,
            $product,
            $aliases,
        ): array {
            $created = [];

            foreach ($aliases as $alias) {
                $record = $this->createApprovedAlias($product, $alias);

                if ($record->wasRecentlyCreated) {
                    $created[] = $record;
                }
            }

            $listing->forceFill(['product_id' => $product->getKey()])->save();

            return $created;
        });

        $failed = false;

        foreach ($createdAliases as $alias) {
            $failed = $this->embeddings->rebuildAlias($alias, true)
                === EntityEmbeddingService::FAILED || $failed;
        }

        return ['product' => $product, 'embedding_failed' => $failed];
    }

    /** @return list<string> */
    public function parseAliases(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $aliases = preg_split('/[\r\n,]+/u', $value) ?: [];
        $unique = [];

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            $normalized = Normalizer::text($alias);

            if ($normalized !== '') {
                $unique[$normalized] = $alias;
            }
        }

        return array_values($unique);
    }

    /** @param array<string, mixed> $data */
    private function assertNoEquivalentProduct(array $data): void
    {
        $normalizedKey = Product::makeNormalizedKey(
            (string) $data['name'],
            $data['brand'] ?? null,
            $data['model'] ?? null,
            $data['variant'] ?? null,
            $data['sku_or_part_number'] ?? null,
        );
        $equivalent = Product::query()
            ->where('normalized_key', $normalizedKey)
            ->orWhere(function (Builder $query) use ($data): void {
                if (! filled($data['model'] ?? null)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where('category_id', (int) $data['category_id'])
                    ->whereRaw('LOWER(TRIM(COALESCE(brand, ?))) = ?', [
                        '',
                        Normalizer::text((string) ($data['brand'] ?? '')),
                    ])
                    ->whereRaw('LOWER(TRIM(COALESCE(model, ?))) = ?', [
                        '',
                        Normalizer::text((string) $data['model']),
                    ])
                    ->whereRaw('LOWER(TRIM(COALESCE(variant, ?))) = ?', [
                        '',
                        Normalizer::text((string) ($data['variant'] ?? '')),
                    ])
                    ->whereRaw('LOWER(TRIM(COALESCE(sku_or_part_number, ?))) = ?', [
                        '',
                        Normalizer::text((string) ($data['sku_or_part_number'] ?? '')),
                    ]);
            })
            ->first();

        if ($equivalent instanceof Product) {
            throw ValidationException::withMessages([
                'name' => __('ui.messages.admin.product.duplicate_identity'),
            ]);
        }
    }

    private function createApprovedAlias(
        Product $product,
        string $alias,
    ): ProductAlias {
        $normalized = Normalizer::text($alias);
        $conflict = ProductAlias::query()
            ->where('normalized_alias', $normalized)
            ->where('product_id', '!=', $product->getKey())
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'aliases' => __('ui.validation.custom.product_alias_conflict'),
            ]);
        }

        return ProductAlias::query()->firstOrCreate(
            [
                'product_id' => $product->getKey(),
                'normalized_alias' => $normalized,
            ],
            ['alias' => $alias],
        );
    }

    private function rebuildNewProductEmbeddings(Product $product): bool
    {
        $product->load('aliases.product');
        $statuses = [$this->embeddings->rebuildProduct($product, true)];

        foreach ($product->aliases as $alias) {
            $statuses[] = $this->embeddings->rebuildAlias($alias, true);
        }

        return in_array(EntityEmbeddingService::FAILED, $statuses, true);
    }

    private function assertUnmatched(Listing $listing): void
    {
        if ($listing->product_id !== null) {
            throw ValidationException::withMessages([
                'listing' => __('ui.messages.admin.listing.already_mapped'),
            ]);
        }
    }

    private function safeMetadata(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $value = trim($value);

        return $value !== '' && strtolower($value) !== 'null'
            ? mb_substr($value, 0, 255, 'UTF-8')
            : '';
    }
}
