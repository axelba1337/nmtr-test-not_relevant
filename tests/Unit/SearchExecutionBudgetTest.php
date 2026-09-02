<?php

namespace Tests\Unit;

use App\Services\Search\SearchExecutionBudget;
use Tests\TestCase;

final class SearchExecutionBudgetTest extends TestCase
{
    public function test_it_tracks_soft_and_hard_request_limits(): void
    {
        config([
            'chatbot.search.soft_timeout_seconds' => 20,
            'chatbot.search.hard_timeout_seconds' => 30,
        ]);
        $now = 100.0;
        $budget = new SearchExecutionBudget(
            static function () use (&$now): float {
                return $now;
            },
        );

        $budget->start();

        $this->assertFalse($budget->softExceeded());
        $this->assertSame(25, $budget->remainingTimeoutSeconds(25));

        $now = 121.25;

        $this->assertTrue($budget->softExceeded());
        $this->assertFalse($budget->hardExceeded());
        $this->assertSame(9, $budget->remainingTimeoutSeconds(25));

        $now = 130.0;

        $this->assertTrue($budget->hardExceeded());
        $this->assertSame(1, $budget->remainingTimeoutSeconds(25));
        $this->assertSame(30000, $budget->elapsedMilliseconds());
    }
}
