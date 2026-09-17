<?php

namespace App\Services\Marketplace;

use RuntimeException;

final class ListingCandidateReviewException extends RuntimeException
{
    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_UNAVAILABLE = 'unavailable';

    public const REASON_VALIDATION = 'validation';

    public const REASON_PROCESSING = 'processing';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
