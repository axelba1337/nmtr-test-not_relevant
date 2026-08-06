<?php

namespace App\Data\Search;

use App\Data\AI\ParsedIntent;
use App\Data\IntegrationFailure;

final readonly class ResolvedIntent
{
    public function __construct(
        public ParsedIntent $intent,
        public bool $usedChatApi,
        public ?IntegrationFailure $chatFailure = null,
    ) {}
}
