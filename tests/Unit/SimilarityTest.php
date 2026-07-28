<?php

namespace Tests\Unit;

use App\Helpers\Similarity;
use PHPUnit\Framework\TestCase;

final class SimilarityTest extends TestCase
{
    public function test_cosine_handles_identical_orthogonal_and_opposite_vectors(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            (float) Similarity::cosine([1, 2], [1, 2]),
            PHP_FLOAT_EPSILON,
        );
        $this->assertSame(0.0, Similarity::cosine([1, 0], [0, 1]));
        $this->assertSame(-1.0, Similarity::cosine([1, 0], [-1, 0]));
    }

    public function test_vector_validation_rejects_invalid_values(): void
    {
        $this->assertNull(Similarity::validatedVector([]));
        $this->assertNull(Similarity::validatedVector([0, 0]));
        $this->assertNull(Similarity::validatedVector(['1', 2]));
        $this->assertNull(Similarity::validatedVector([[1], 2]));
        $this->assertNull(Similarity::validatedVector([INF, 1]));
        $this->assertNull(Similarity::validatedVector([NAN, 1]));
    }

    public function test_vector_validation_rejects_an_unexpected_dimension(): void
    {
        $this->assertNull(Similarity::validatedVector([1, 2], 3));
        $this->assertNull(Similarity::cosine([1, 2], [1, 2, 3]));
    }

    public function test_vector_validation_returns_normalized_numeric_types(): void
    {
        $this->assertSame(
            [1.0, 2.5],
            Similarity::validatedVector([1, 2.5], 2),
        );
    }
}
