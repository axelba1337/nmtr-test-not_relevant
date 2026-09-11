<?php

namespace App\Services\Embedding;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

final class ProductEmbeddingStatus
{
    public const ALL = 'all';

    private const CHUNK_SIZE = 100;

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::ALL,
            Product::EMBEDDING_READY,
            Product::EMBEDDING_NEEDS_REBUILD,
            Product::EMBEDDING_FAILED,
        ];
    }

    public function normalize(mixed $value): string
    {
        return is_string($value) && in_array($value, self::values(), true)
            ? $value
            : self::ALL;
    }

    public function applyFilter(Builder $query, string $status): Builder
    {
        if ($status === self::ALL) {
            return $query;
        }

        $ids = [];

        foreach ($this->products($query) as $product) {
            if ($this->status($product) === $status) {
                $ids[] = $product->getKey();
            }
        }

        return $ids === []
            ? $query->whereRaw('1 = 0')
            : $query->whereKey($ids);
    }

    public function applyOperationalOrder(Builder $query): Builder
    {
        $priorities = [
            Product::EMBEDDING_FAILED => 0,
            Product::EMBEDDING_NEEDS_REBUILD => 1,
            Product::EMBEDDING_READY => 2,
        ];
        $bindings = [];
        $cases = [];

        foreach ($this->products($query) as $product) {
            $cases[] = 'WHEN ? THEN '.($priorities[$this->status($product)] ?? 3);
            $bindings[] = $product->getKey();
        }

        if ($cases !== []) {
            $query->orderByRaw(
                'CASE products.id '.implode(' ', $cases).' ELSE 3 END',
                $bindings,
            );
        }

        return $query->orderBy('name')->orderByDesc('id');
    }

    public function status(Product $product): string
    {
        return $product->embeddingStatus(
            (string) config('ai.embedding.model'),
            (int) config('ai.embedding.dimensions'),
        );
    }

    private function products(Builder $query): iterable
    {
        $scan = clone $query;
        $scan->setEagerLoads([]);

        return $scan
            ->reorder()
            ->with('category:id,name')
            ->lazyById(self::CHUNK_SIZE, 'products.id', 'id');
    }
}
