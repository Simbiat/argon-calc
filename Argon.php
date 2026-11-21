<?php

declare(strict_types=1);

namespace Simbiat;

/**
 * Calculate Argon2 parameters that fit a time budget.
 */
final readonly class Argon
{
    /**
     * Default minimum time_cost, as recommended by OWASP.
     * The clamp in calc() turns 0 into 1, so a mutated value (0) gives the same result. Infection cannot detect it.
     */
    // @infection-ignore-all
    private const int DEFAULT_MIN_TIME_COST = 1;

    /**
     * Default thread count (p), as recommended by OWASP.
     * The clamp in calc() turns 0 into 1, so a mutated value (0) gives the same result. Infection cannot detect it.
     */
    // @infection-ignore-all
    private const int DEFAULT_THREADS = 1;

    /**
     * Median index: what item to choose from the sorted benchmark samples.
     */
    private const int MEDIAN_INDEX = 2;

    /**
     * Number of samples for benchmark.
     */
    private const int SAMPLE_COUNT = 5;

    /**
     * @var \Closure(int, int, int, string): float
     */
    private \Closure $sampler;

    /**
     * @param (\Closure(int, int, int, string): float)|null $sampler Returns the seconds that one hash takes. Default: real password_hash() timing.
     */
    public function __construct(?\Closure $sampler = null)
    {
        $this->sampler = $sampler ?? self::sample(...);
    }

    /**
     * Calculate Argon2 parameters that fit a time budget.
     *
     * Tuning order: time_cost is raised first. Any time budget left over is then spent on memory.
     * This avoids allocating large memory blocks before a working time_cost is known.
     *
     * @param float  $max_time_spent   Target hash time in seconds. Common recommendations are from 0.1 to 1 second. Default is 0.25.
     * @param int    $min_memory_kib   Minimum memory_cost in KiB. Default is 47104 (47 MiB) as recommended by OWASP.
     * @param int    $max_memory_kib   Hard ceiling for memory_cost in KiB. Default is 262144 (256 MiB).
     * @param int    $min_time_cost    Minimum time_cost. Default is 1 as recommended by OWASP.
     * @param int    $threads          Argon2 parallelism (p). Default 1 as recommended by OWASP.
     * @param string $string_to_test   Sample string used only for timing. Not a real credential.
     * @param int    $max_search_steps Safety cap: no search loop will run more than these many steps.
     *
     * @return array{memory_cost:int,time_cost:int,threads:int}
     *
     * @throws \LogicException|\RuntimeException
     */
    #[\NoDiscard(message: 'The calculated Argon2 parameters must be used.')]
    public function calc(float $max_time_spent = 0.25, int $min_memory_kib = 47_104, int $max_memory_kib = 262_144, int $min_time_cost = self::DEFAULT_MIN_TIME_COST, int $threads = self::DEFAULT_THREADS, string $string_to_test = 'rel@t!velyl0ngte$t5tr1ng', int $max_search_steps = 64): array
    {
        if ($max_time_spent <= 0.0) {
            $max_time_spent = 0.25;
        }

        // Argon2 accepts 1 thread and 1 iteration at minimum, so 0 is invalid, too.
        $threads = \max(1, $threads);
        $min_time_cost = \max(1, $min_time_cost);
        $min_memory_kib = \max(1_024, $min_memory_kib);
        $max_memory_kib = \max($min_memory_kib, $max_memory_kib);

        // Check the floor itself first. If even the floor is too slow, stop here.
        // Do not search below the floor to hit the time budget.
        $floor_time = $this->measure($min_memory_kib, $min_time_cost, $threads, $string_to_test);
        if ($floor_time > $max_time_spent) {
            throw new \RuntimeException(
                \sprintf(
                    'Argon2id floor (t=%d, m=%dKiB) takes %.3fs, above max_time_spent of %.3fs. Raise max_time_spent or lower min_memory_kib and min_time_cost.',
                    $min_time_cost,
                    $min_memory_kib,
                    $floor_time,
                    $max_time_spent,
                ),
            );
        }

        // Step 1: raise time_cost at the memory floor. Keep only the last value confirmed to be under budget - never the first one that goes over.
        $last_good_time_cost = $min_time_cost;
        for ($step = 0; $step < $max_search_steps; ++$step) {
            $candidate = $last_good_time_cost + 1;
            $elapsed_time = $this->measure($min_memory_kib, $candidate, $threads, $string_to_test);
            if ($elapsed_time > $max_time_spent) {
                break;
            }

            $last_good_time_cost = $candidate;
        }

        $time_cost = $last_good_time_cost;

        // Step 2: spend any remaining budget on memory, at the time_cost just found. Steps of +50% reach the ceiling in a handful of tries.
        // Integer math: (3 * m + 1) >> 1 is ceil(m * 1.5). A right shift by 1 divides by 2 and cannot throw a division error.
        $last_good_memory = $min_memory_kib;
        $candidate_memory = $min_memory_kib;
        for ($step = 0; $step < $max_search_steps; ++$step) {
            $candidate_memory = \min($max_memory_kib, (($candidate_memory * 3) + 1) >> 1);
            $elapsed_time = $this->measure($candidate_memory, $time_cost, $threads, $string_to_test);
            if ($elapsed_time > $max_time_spent) {
                break;
            }

            $last_good_memory = $candidate_memory;
            if ($candidate_memory >= $max_memory_kib) {
                // Hit the hard ceiling while still under budget
                break;
            }
        }

        return ['memory_cost' => $last_good_memory, 'time_cost' => $time_cost, 'threads' => $threads];
    }

    /**
     * Measure median hash time for given parameters, over SAMPLE_COUNT samples.
     * Median resists single-sample noise (GC pause, scheduler jitter) better than a single timed run.
     *
     * @param int    $memory_cost
     * @param int    $time_cost
     * @param int    $threads
     * @param string $string_to_test
     *
     * @return float
     *
     * @throws \LogicException
     */
    private function measure(int $memory_cost, int $time_cost, int $threads, string $string_to_test): float
    {
        $samples = [];
        for ($iteration = 0; $iteration < self::SAMPLE_COUNT; ++$iteration) {
            $samples[] = ($this->sampler)($memory_cost, $time_cost, $threads, $string_to_test);
        }

        \sort($samples);

        // The guard below cannot be reached: the loop always adds SAMPLE_COUNT items. It exists only for static analysis.
        // @infection-ignore-all
        return $samples[self::MEDIAN_INDEX] ?? throw new \LogicException(
            \sprintf('Expected %d samples, got %d', self::SAMPLE_COUNT, \count($samples)),
        );
    }

    /**
     * Default sampler function to use for benchmarking.
     *
     * @param int    $memory_cost    How much memory to use
     * @param int    $time_cost      How much time to spend on hashing
     * @param int    $threads        How many threads to use
     * @param string $string_to_test String to test hashing on
     *
     * @return float
     */
    private static function sample(int $memory_cost, int $time_cost, int $threads, string $string_to_test): float
    {
        /**
         * The float casts are only for strict static analysis. int and float give the same result here, so Infection cannot detect them.
         *
         * @infection-ignore-all
         */
        $start = (float) \hrtime(as_number: true);
        (void) \password_hash($string_to_test, \PASSWORD_ARGON2ID, ['memory_cost' => $memory_cost, 'time_cost' => $time_cost, 'threads' => $threads]);
        // @infection-ignore-all
        $end = (float) \hrtime(as_number: true);

        return ($end - $start) / 1e9;
    }
}
