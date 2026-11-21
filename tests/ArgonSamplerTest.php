<?php

declare(strict_types=1);

namespace Simbiat\Argon\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Simbiat\Argon;

/**
 * Tests for the default sampler of Argon, which uses the real password_hash().
 *
 * @internal
 */
#[CoversClass(Argon::class)]
final class ArgonSamplerTest extends TestCase
{
    /**
     * Default string to use for benchmarking.
     */
    private const string DEFAULT_STRING = 'rel@t!velyl0ngte$t5tr1ng';

    /**
     * The real sampler rejects a floor that is above the budget.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function defaultSamplerRejectsFloorAboveBudget(): void
    {
        self::requireArgonSupport();

        $this->expectException(\RuntimeException::class);
        (void) new Argon()->calc(max_time_spent: 0.000_01, min_memory_kib: 1_024, max_memory_kib: 1_024, max_search_steps: 1);
    }

    /**
     * The real sampler times a real hash.
     *
     * @throws \LogicException|\RuntimeException
     */
    #[Test]
    public function defaultSamplerTimesARealHash(): void
    {
        self::requireArgonSupport();

        self::assertSame(
            ['memory_cost' => 1_024, 'time_cost' => 2, 'threads' => 1],
            new Argon()->calc(max_time_spent: 60.0, min_memory_kib: 1_024, max_memory_kib: 1_024, max_search_steps: 1),
        );
    }

    /**
     * Every parameter reaches password_hash(). PHP rejects a value below its minimum, and ignores a parameter that is missing.
     *
     * @param int $memory  Memory cost to test.
     * @param int $time    Time cost to test.
     * @param int $threads Threads to test.
     *
     * @throws \ReflectionException|\RuntimeException
     */
    #[DataProvider('provideSampleForwardsEveryParameterToHashCases')]
    #[Test]
    public function sampleForwardsEveryParameterToHash(int $memory, int $time, int $threads): void
    {
        self::requireArgonSupport();

        $this->expectException(\ValueError::class);
        (void) self::sample($memory, $time, $threads);
    }

    /**
     * @return \Generator<string, array{int, int, int}>
     */
    public static function provideSampleForwardsEveryParameterToHashCases(): iterable
    {
        yield 'memory_cost below the PHP minimum' => [1, 1, 1];

        yield 'time_cost below the PHP minimum' => [1_024, 0, 1];

        yield 'threads below the PHP minimum' => [1_024, 1, 0];
    }

    /**
     * The sampler returns seconds, not nanoseconds.
     *
     * @throws \ReflectionException|\RuntimeException
     */
    #[Test]
    public function sampleReturnsElapsedSeconds(): void
    {
        self::requireArgonSupport();

        $elapsed = self::sample(memory: 1_024, time: 1, threads: 1);

        self::assertGreaterThan(0.0, $elapsed);
        self::assertLessThan(5.0, $elapsed);
    }

    /**
     * Skip the test when PHP has no Argon2id.
     *
     * @return void
     *
     * @throws SkippedWithMessageException
     */
    private static function requireArgonSupport(): void
    {
        if (\defined('PASSWORD_ARGON2ID')) {
            return;
        }

        self::markTestSkipped('PHP has no Argon2id support.');
    }

    /**
     * Call the private default sampler.
     *
     * @param int $memory  Memory cost.
     * @param int $time    Time cost.
     * @param int $threads Threads.
     *
     * @return float
     *
     * @throws \ReflectionException
     */
    private static function sample(int $memory, int $time, int $threads): float
    {
        // The cast is safe: Argon::sample() has a float return type.
        /**
         * @mago-expect    analysis:invalid-type-cast
         *
         * @phpstan-ignore cast.double
         * */
        return (float) new \ReflectionMethod(Argon::class, 'sample')->invoke(null, $memory, $time, $threads, self::DEFAULT_STRING);
    }
}
