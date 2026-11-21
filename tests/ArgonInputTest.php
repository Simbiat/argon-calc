<?php

declare(strict_types=1);

namespace Simbiat\Argon\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Simbiat\Argon;

/**
 * Tests for the input clamping and the median of Argon::calc().
 *
 * @internal
 */
#[CoversClass(Argon::class)]
final class ArgonInputTest extends AbstractArgonTestCase
{
    /**
     * Invalid inputs are replaced with defaults, in every combination.
     *
     * @param float                                                 $budget     Time budget given to calc().
     * @param int                                                   $threads    Threads given to calc().
     * @param int                                                   $time_cost  Minimum time cost given to calc().
     * @param int                                                   $memory     Minimum memory given to calc().
     * @param int                                                   $max_memory Maximum memory given to calc().
     * @param array{memory_cost: int, time_cost: int, threads: int} $expected   Expected result.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[DataProvider('provideEveryClampCombinationCases')]
    #[Test]
    public function everyClampCombination(float $budget, int $threads, int $time_cost, int $memory, int $max_memory, array $expected): void
    {
        $argon = new Argon(static fn(): float => 0.0);

        self::assertSame($expected, $argon->calc(
            $budget,
            $memory,
            $max_memory,
            $time_cost,
            $threads,
            max_search_steps: 1,
        ));
    }

    /**
     * @return \Generator<string, array{float, int, int, int, int, array{memory_cost: int, time_cost: int, threads: int}}>
     */
    public static function provideEveryClampCombinationCases(): iterable
    {
        for ($mask = 0; $mask < 32; ++$mask) {
            $bad_budget = ($mask & 1) !== 0;
            $bad_threads = ($mask & 2) !== 0;
            $bad_time_cost = ($mask & 4) !== 0;
            $bad_memory = ($mask & 8) !== 0;
            $inverted = ($mask & 16) !== 0;

            $min_memory = $bad_memory
                ? 1_024
                : 2_048;
            $time_cost = $bad_time_cost
                ? 1
                : 3;
            $threads = $bad_threads
                ? 1
                : 2;

            $expected_memory = $inverted
                ? $min_memory
                : (int) \ceil((float) $min_memory * 1.5);

            yield \sprintf('mask %05b', $mask) => [
                $bad_budget ? 0.0 : 0.25,
                $bad_threads ? 0 : 2,
                $bad_time_cost ? 0 : 3,
                $bad_memory ? 0 : 2_048,
                $inverted ? 512 : 100_000,
                ['memory_cost' => $expected_memory, 'time_cost' => $time_cost + 1, 'threads' => $threads],
            ];
        }
    }

    /**
     * A median above the budget stops the calculation.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function floorMedianAboveBudgetThrows(): void
    {
        $argon = new Argon($this->queued());

        $this->expectException(\RuntimeException::class);
        (void) $argon->calc(min_memory_kib: 1_024, max_search_steps: 0);
    }

    /**
     * The sorted median is compared with the budget. A median that equals the budget is accepted.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function floorMedianEqualToBudgetIsAccepted(): void
    {
        $argon = new Argon($this->queued());

        self::assertSame(
            ['memory_cost' => 1_024, 'time_cost' => 1, 'threads' => 1],
            $argon->calc(max_time_spent: 0.3, min_memory_kib: 1_024, max_search_steps: 0),
        );
    }

    /**
     * The memory floor is 1024 KiB.
     *
     * @param int $given    Minimum memory given to calc().
     * @param int $expected Memory that the first sampler call must use.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[DataProvider('provideMinimumMemoryFloorCases')]
    #[Test]
    public function minimumMemoryFloor(int $given, int $expected): void
    {
        $argon = new Argon($this->sampler(static fn(): float => 0.0));
        (void) $argon->calc(max_time_spent: 1.0, min_memory_kib: $given, max_search_steps: 0);

        self::assertSame([$expected, 1, 1, self::DEFAULT_STRING], \array_first($this->calls));
    }

    /**
     * @return \Generator<string, array{int, int}>
     */
    public static function provideMinimumMemoryFloorCases(): iterable
    {
        yield 'zero' => [0, 1_024];

        yield 'one below floor' => [1_023, 1_024];

        yield 'on floor' => [1_024, 1_024];

        yield 'above floor' => [1_025, 1_025];
    }

    /**
     * A budget of zero or less becomes 250 ms.
     *
     * @param float $budget Time budget given to calc().
     *
     * @throws \LogicException|\RuntimeException
     */
    #[DataProvider('provideNonPositiveBudgetFallsBackTo250MillisecondsCases')]
    #[Test]
    public function nonPositiveBudgetFallsBackTo250Milliseconds(float $budget): void
    {
        $fits = new Argon(static fn(): float => 0.25);
        self::assertSame(
            ['memory_cost' => 1_024, 'time_cost' => 1, 'threads' => 1],
            $fits->calc(max_time_spent: $budget, min_memory_kib: 1_024, max_search_steps: 0),
        );

        $too_slow = new Argon(static fn(): float => 0.26);
        $this->expectException(\RuntimeException::class);
        (void) $too_slow->calc(max_time_spent: $budget, min_memory_kib: 1_024, max_search_steps: 0);
    }

    /**
     * @return \Generator<string, array{float}>
     */
    public static function provideNonPositiveBudgetFallsBackTo250MillisecondsCases(): iterable
    {
        yield 'zero' => [0.0];

        yield 'negative' => [-1.0];
    }

    /**
     * Build a sampler that returns the given times in order. Extra calls return 0.0.
     *
     * @return \Closure(int, int, int, string): float
     */
    private function queued(): \Closure
    {
        $values = [0.5, 0.1, 0.4, 0.2, 0.3];

        return $this->sampler(fn(): float => $values[\count($this->calls) - 1] ?? 0.0);
    }
}
