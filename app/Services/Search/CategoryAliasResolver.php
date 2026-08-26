<?php

namespace App\Services\Search;

use App\Data\Search\CategoryResolution;
use App\Helpers\Normalizer;
use App\Models\CategoryAlias;
use Illuminate\Support\Facades\Schema;

final class CategoryAliasResolver
{
    public function resolve(string $text): ?CategoryResolution
    {
        $normalized = Normalizer::text($text);

        if ($normalized === '') {
            return null;
        }

        if (! Schema::hasTable('category_aliases')) {
            return null;
        }

        $aliases = CategoryAlias::query()
            ->active()
            ->whereHas('category', static fn ($query) => $query->active())
            ->with('category')
            ->orderBy('id')
            ->get()
            ->sortByDesc(static fn (CategoryAlias $alias): int => mb_strlen(
                (string) $alias->normalized_alias,
                'UTF-8',
            ));

        foreach ($aliases as $alias) {
            $needle = (string) $alias->normalized_alias;

            if ($needle !== '' && preg_match(
                '/(?<![\pL\pN])'.preg_quote($needle, '/').'(?![\pL\pN])/u',
                $normalized,
            ) === 1) {
                return new CategoryResolution(
                    $alias->category,
                    (string) $alias->alias,
                    str_contains((string) $alias->source, 'typo')
                        ? 'category_alias_curated_typo'
                        : 'category_alias',
                );
            }
        }

        return null;
    }
}
