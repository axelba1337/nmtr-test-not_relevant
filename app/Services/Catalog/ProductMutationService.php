<?php

namespace App\Services\Catalog;

use App\Helpers\Normalizer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductMutationService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $aliases
     */
    public function create(array $data, array $aliases): Product
    {
        $this->assertNormalizedKeyIsUnique($data);

        return DB::transaction(function () use ($data, $aliases): Product {
            $product = Product::query()->create($data);
            $this->syncAliases($product, $aliases);

            return $product;
        });
    }

    /** @return list<string> */
    public function normalizeAliases(
        mixed $value,
        string $separator = '/[\r\n,]+/u',
    ): array {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $aliases = preg_split($separator, $value) ?: [];
        $normalized = [];

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            $key = Normalizer::text($alias);

            if ($key !== '') {
                $normalized[$key] = $alias;
            }
        }

        return array_values($normalized);
    }

    /** @param array<string, mixed> $data */
    public function assertNormalizedKeyIsUnique(
        array $data,
        ?Product $except = null,
    ): void {
        $key = Product::makeNormalizedKey(
            (string) $data['name'],
            $data['brand'] ?? null,
            $data['model'] ?? null,
            $data['variant'] ?? null,
            $data['sku_or_part_number'] ?? null,
        );
        $exists = Product::query()
            ->where('normalized_key', $key)
            ->when($except !== null, fn (Builder $query): Builder => $query
                ->where('id', '!=', $except->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('ui.messages.admin.product.duplicate_identity'),
            ]);
        }
    }

    /** @param list<string> $aliases */
    public function syncAliases(Product $product, array $aliases): void
    {
        $wanted = [];

        foreach ($aliases as $alias) {
            $wanted[Normalizer::text($alias)] = $alias;
        }

        $existing = $product->aliases()->get()->keyBy('normalized_alias');
        $product->aliases()
            ->whereNotIn('normalized_alias', array_keys($wanted))
            ->delete();

        foreach ($wanted as $normalizedAlias => $alias) {
            $record = $existing->get($normalizedAlias);

            if ($record === null) {
                $product->aliases()->create([
                    'alias' => $alias,
                    'normalized_alias' => $normalizedAlias,
                ]);
            } elseif ($record->alias !== $alias) {
                $record->update(['alias' => $alias]);
            }
        }
    }
}
