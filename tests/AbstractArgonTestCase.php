<?php

declare(strict_types=1);

namespace Simbiat\Argon\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the Argon tests: a sampler that records its calls.
 *
 * @internal
 */
abstract class AbstractArgonTestCase extends TestCase
{
    /**
     * Default string to use for benchmarking.
     */
    protected const string DEFAULT_STRING = 'rel@t!velyl0ngte$t5tr1ng';

    /**
     * @var list<array{int, int, int, string}>
     */
    protected array $calls = [];

    /**
     * Build a sampler that records each call and returns the time from a callback.
     *
     * @param callable(int, int): float $time_for Receives memory_cost and time_cost, returns seconds.
     *
     * @return \Closure(int, int, int, string): float
     */
    protected function sampler(callable $time_for): \Closure
    {
        return function (int $memory, int $time, int $threads, string $text) use ($time_for): float {
            $this->calls[] = [$memory, $time, $threads, $text];

            return $time_for($memory, $time);
        };
    }
}
