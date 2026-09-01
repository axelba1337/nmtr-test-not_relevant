<?php

namespace App\Services\Search;

use App\Helpers\Normalizer;
use App\Models\Category;
use App\Models\Platform;

final class ScopeValidationService
{
    public function isInvalidInput(string $normalized): bool
    {
        if ($normalized === ''
            || preg_match('/[\pL\pN]/u', $normalized) !== 1) {
            return true;
        }

        $compact = preg_replace('/[^\pL\pN]+/u', '', $normalized) ?? '';

        if (mb_strlen($compact, 'UTF-8') < 2) {
            return true;
        }

        return in_array(
            $compact,
            (array) config('neomatra.scope.invalid_tokens', []),
            true,
        );
    }

    public function isOutOfScope(string $normalized): bool
    {
        foreach ((array) config('neomatra.scope.unsupported_keywords', []) as $keyword) {
            $keyword = Normalizer::text((string) $keyword);

            if ($keyword !== '' && preg_match(
                '/(?<![\pL\pN])'.preg_quote($keyword, '/').'(?![\pL\pN])/u',
                $normalized,
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{name: string, slug: string}> */
    public function supportedCategories(): array
    {
        $slugs = array_values((array) config(
            'neomatra.scope.supported_category_slugs',
            [],
        ));

        return Category::query()
            ->active()
            ->whereIn('slug', $slugs)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(static fn (Category $category): array => [
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
            ])
            ->values()
            ->all();
    }

    /** @return list<array{name: string, platform: string, base_url: string, search_term: string}> */
    public function externalHandoff(string $message): array
    {
        $preferred = (array) config(
            'neomatra.scope.external_handoff_platform_slugs',
            ['olx', 'facebook'],
        );

        return Platform::query()
            ->publiclyAvailable()
            ->where('status', '!=', Platform::STATUS_ERROR)
            ->whereIn('slug', $preferred)
            ->whereNotNull('base_url')
            ->get(['name', 'slug', 'base_url'])
            ->filter(static fn (Platform $platform): bool => filter_var(
                $platform->base_url,
                FILTER_VALIDATE_URL,
            ) !== false)
            ->sortBy(static function (Platform $platform) use ($preferred): int {
                $position = array_search($platform->slug, $preferred, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->map(static fn (Platform $platform): array => [
                'name' => __('ui.chat.backend.external_search', [
                    'platform' => $platform->name,
                ]),
                'platform' => (string) $platform->name,
                'base_url' => (string) $platform->base_url,
                'search_term' => mb_substr(trim($message), 0, 200, 'UTF-8'),
            ])
            ->values()
            ->all();
    }
}
