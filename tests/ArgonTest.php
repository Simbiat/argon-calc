<?php

declare(strict_types=1);

namespace Simbiat\Argon\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Simbiat\Argon;

/**
 * Tests for the search logic of Argon::calc().
 *
 * @internal
 */
#[CoversClass(Argon::class)]
final class ArgonTest extends AbstractArgonTestCase
{
    /**
     * Default arguments: minimum memory, memory ceiling, search step cap, thread count and test string.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function defaultArgumentsAreUsed(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.0));

        // 47104 grows by +50% to 70656, 105984, 158976, 238464, and then stops at the 262144 ceiling.
        self::assertSame(
            ['memory_cost' => 262_144, 'time_cost' => 65, 'threads' => 1],
            $argon->calc(),
        );
        self::assertSame([47_104, 1, 1, self::DEFAULT_STRING], \array_first($this->calls));
    }

    /**
     * The floor check runs before any search and the message shows the floor values.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function floorAboveBudgetThrowsBeforeAnySearch(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 1.0));

        try {
            (void) $argon->calc(max_time_spent: 0.5, min_memory_kib: 1_024);
            self::fail('Expected RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Argon2id floor (t=1, m=1024KiB) takes 1.000s, above max_time_spent of 0.500s. Raise max_time_spent or lower min_memory_kib and min_time_cost.',
                $exception->getMessage(),
            );
        }

        self::assertCount(5, $this->calls);
    }

    /**
     * Time cost is raised first. The budget that remains goes to memory.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function leftoverBudgetGoesToMemoryAfterTimeCost(): void
    {
        $argon = new Argon($this->sampler(
            static fn(int $memory, int $time): float => (0.001 * (float) $time) + ((float) $memory * 1e-6),
        ));

        self::assertSame(
            ['memory_cost' => 1_536, 'time_cost' => 8, 'threads' => 1],
            $argon->calc(max_time_spent: 0.01, min_memory_kib: 1_024),
        );
        // 1 floor + 8 time candidates + 2 memory candidates = 11 measures of 5 samples.
        // A search that does not stop at the first over-budget value makes more calls.
        self::assertCount(55, $this->calls);
        self::assertSame([1_024, 1, 1, self::DEFAULT_STRING], \array_first($this->calls));
        self::assertSame([2_304, 8, 1, self::DEFAULT_STRING], \array_last($this->calls));
    }

    /**
     * Memory stops at the ceiling, and the time search stops at the step cap.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function memoryStopsAtCeiling(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.0));

        self::assertSame(
            ['memory_cost' => 4_096, 'time_cost' => 65, 'threads' => 1],
            $argon->calc(max_time_spent: 1.0, min_memory_kib: 1_024, max_memory_kib: 4_096),
        );
        // 1 floor + 64 time candidates + 4 memory candidates (1536, 2304, 3456, 4096).
        self::assertCount(345, $this->calls);
    }

    /**
     * Memory candidates round up and the step cap limits both loops.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function searchStepCapAndMemoryRoundingUp(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.0));

        // 1025 * 1.5 = 1537.5, rounded up to 1538.
        self::assertSame(
            ['memory_cost' => 1_538, 'time_cost' => 2, 'threads' => 1],
            $argon->calc(max_time_spent: 1.0, min_memory_kib: 1_025, max_memory_kib: 8_192, max_search_steps: 1),
        );
        self::assertCount(15, $this->calls);
    }

    /**
     * The threads and the test string reach every sampler call.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function threadsAndStringReachTheSampler(): void
    {
        $result = new Argon($this->sampler(static fn(): float => 0.0))->calc(
            max_time_spent: 1.0,
            min_memory_kib: 1_024,
            threads: 3,
            string_to_test: 'abc',
            max_search_steps: 2,
        );

        self::assertSame(3, $result['threads']);
        // 1 floor + 2 time candidates + 2 memory candidates = 5 measures of 5 samples.
        self::assertCount(25, $this->calls);
        foreach ($this->calls as $call) {
            self::assertSame(3, $call[2]);
            self::assertSame('abc', $call[3]);
        }
    }

    /**
     * A time that equals the budget is accepted.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function timeEqualToBudgetIsAccepted(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.25));

        self::assertSame(
            ['memory_cost' => 3_456, 'time_cost' => 4, 'threads' => 1],
            $argon->calc(min_memory_kib: 1_024, max_search_steps: 3),
        );
        self::assertCount(35, $this->calls);
    }

    /**
     * With zero search steps, only the floor is measured and returned.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function zeroSearchStepsReturnsFloor(): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.0));

        self::assertSame(
            ['memory_cost' => 2_048, 'time_cost' => 3, 'threads' => 1],
            $argon->calc(max_time_spent: 1.0, min_memory_kib: 2_048, min_time_cost: 3, max_search_steps: 0),
        );
        self::assertCount(5, $this->calls);
        self::assertSame([2_048, 3, 1, self::DEFAULT_STRING], \array_first($this->calls));
    }
}
