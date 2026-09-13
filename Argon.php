<?php

declare(strict_types=1);

namespace Simbiat;

/**
 * Calculate Argon2 parameters that fit a time budget.
 */
class Argon
{
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
     */
    public function calc(float $max_time_spent = 0.25, int $min_memory_kib = 47104, int $max_memory_kib = 262144, int $min_time_cost = 1, int $threads = 1, string $string_to_test = 'rel@t!velyl0ngte$t5tr1ng', int $max_search_steps = 64): array
    {
        if ($max_time_spent <= 0.0) {
            $max_time_spent = 0.25;
        }
        if ($threads < 0) {
            $threads = 1;
        }
        if ($min_time_cost < 0) {
            $min_time_cost = 1;
        }
        if ($min_memory_kib < 1024) {
            $min_memory_kib = 1024;
        }
        if ($max_memory_kib < $min_memory_kib) {
            $max_memory_kib = $min_memory_kib;
        }

        // Check the floor itself first. If even the floor is too slow, stop here.
        // Do not search below the floor to hit the time budget.
        $floor_time = $this->measure($min_memory_kib, $min_time_cost, $threads, $string_to_test);
        if ($floor_time > $max_time_spent) {
            throw new \RuntimeException(\sprintf(
                'Argon2id floor (t=%d, m=%dKiB) takes %.3fs, above max_time_spent of %.3fs on this host. Using floor values anyway; consider raising max_time_spent.',
                $min_time_cost,
                $min_memory_kib,
                $floor_time,
                $max_time_spent
            ));
        }

        // Step 1: raise time_cost at the memory floor. Keep only the last value confirmed to be under budget - never the first one that goes over.
        $last_good_time_cost = $min_time_cost;
        for ($step = 0; $step < $max_search_steps; $step++) {
            $candidate = $last_good_time_cost + 1;
            if ($this->measure($min_memory_kib, $candidate, $threads, $string_to_test) > $max_time_spent) {
                break;
            }
            $last_good_time_cost = $candidate;
        }
        $time_cost = $last_good_time_cost;

        // Step 2: spend any remaining budget on memory, at the time_cost just found. Steps of +50% reach the ceiling in a handful of tries.
        $last_good_memory = $min_memory_kib;
        $candidate_memory = $min_memory_kib;
        for ($step = 0; $step < $max_search_steps; $step++) {
            $candidate_memory = (int) \min($max_memory_kib, (int) \ceil($candidate_memory * 1.5));
            if ($this->measure($candidate_memory, $time_cost, $threads, $string_to_test) > $max_time_spent) {
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
     * Measure median hash time for given parameters, over 5 samples.
     * Median resists single-sample noise (GC pause, scheduler jitter) better than a single timed run.
     *
     * @param int    $memory_cost
     * @param int    $time_cost
     * @param int    $threads
     * @param string $string_to_test
     *
     * @return float
     */
    private function measure(int $memory_cost, int $time_cost, int $threads, string $string_to_test): float
    {
        $samples = [];
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $start = \hrtime(true);
            // We do not store the results, and this is not a real credential
            /** @noinspection UnusedFunctionResultInspection */
            \password_hash($string_to_test, \PASSWORD_ARGON2ID, \compact('memory_cost', 'time_cost', 'threads'));
            $samples[] = (\hrtime(true) - $start) / 1e9;
        }
        \sort($samples);
        return $samples[2];
    }
}
