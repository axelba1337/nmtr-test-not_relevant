<?php

namespace App\Contracts\AI;

use App\Data\AI\EmbeddingPurpose;
use App\Data\AI\EmbeddingResult;

interface EmbeddingClient
{
    public function embed(
        string $text,
        EmbeddingPurpose $purpose,
        ?string $title = null,
    ): EmbeddingResult;

    public function provider(): string;

    public function model(): string;

    public function dimensions(): int;
}
