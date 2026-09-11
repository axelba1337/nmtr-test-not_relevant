<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class AdminIndexSort
{
    public const DEFAULT = 'newest';

    public const PER_PAGE = 50;

    /** @return list<string> */
    public static function values(): array
    {
        return [self::DEFAULT, 'oldest', 'alphabetical', 'status'];
    }

    /** @param list<string>|null $values */
    public static function normalize(mixed $value, ?array $values = null): string
    {
        return is_string($value)
            && in_array($value, $values ?? self::values(), true)
            ? $value
            : self::DEFAULT;
    }

    public static function apply(
        Builder $query,
        string $sort,
        string $alphabeticalColumn,
        Closure $statusOrder,
    ): Builder {
        return match ($sort) {
            'oldest' => $query
                ->orderBy('created_at')
                ->orderBy('id'),
            'alphabetical' => $query
                ->orderBy($alphabeticalColumn)
                ->orderByDesc('id'),
            'status' => $statusOrder($query)->orderByDesc('id'),
            default => $query
                ->orderByDesc('created_at')
                ->orderByDesc('id'),
        };
    }
}
