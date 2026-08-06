<?php

namespace App\Data\AI;

enum EmbeddingPurpose: string
{
    case Query = 'query';
    case Product = 'product';
    case Alias = 'alias';
    case Knowledge = 'knowledge';

    public function isQuery(): bool
    {
        return $this === self::Query;
    }
}
