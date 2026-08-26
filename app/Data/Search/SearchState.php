<?php

namespace App\Data\Search;

final class SearchState
{
    public const MATCHED = 'MATCHED';

    public const NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';

    public const FILTER_TOO_STRICT = 'FILTER_TOO_STRICT';

    public const ALTERNATIVE_PRODUCT = 'ALTERNATIVE_PRODUCT';

    public const NO_ACTIVE_LISTING = 'NO_ACTIVE_LISTING';

    public const UNKNOWN_PRODUCT = 'UNKNOWN_PRODUCT';

    public const OUT_OF_SCOPE = 'OUT_OF_SCOPE';

    public const PLATFORM_UNAVAILABLE = 'PLATFORM_UNAVAILABLE';

    public const PARTIAL_RESULT = 'PARTIAL_RESULT';

    public const SEARCH_TIMEOUT = 'SEARCH_TIMEOUT';

    public const PROVIDER_ERROR = 'PROVIDER_ERROR';

    public const INVALID_INPUT = 'INVALID_INPUT';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MATCHED,
            self::NEEDS_CLARIFICATION,
            self::FILTER_TOO_STRICT,
            self::ALTERNATIVE_PRODUCT,
            self::NO_ACTIVE_LISTING,
            self::UNKNOWN_PRODUCT,
            self::OUT_OF_SCOPE,
            self::PLATFORM_UNAVAILABLE,
            self::PARTIAL_RESULT,
            self::SEARCH_TIMEOUT,
            self::PROVIDER_ERROR,
            self::INVALID_INPUT,
        ];
    }
}
