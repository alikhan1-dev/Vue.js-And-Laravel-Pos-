<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\DeadlockException;

/**
 * Retry a DB transaction on deadlock (SQLSTATE 40001 / ER_LOCK_DEADLOCK).
 *
 * High-concurrency POS environments can trigger deadlocks when multiple
 * terminals lock overlapping stock_cache or sale rows. This helper wraps
 * DB::transaction() with configurable retry + exponential back-off so
 * transient deadlocks are recovered without surfacing errors to the user.
 */
trait RetryOnDeadlock
{
    protected function transactionWithRetry(Closure $callback, int $maxAttempts = 3, int $baseDelayMs = 50)
    {
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                return DB::transaction($callback);
            } catch (DeadlockException $e) {
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                $delayMs = $baseDelayMs * (2 ** ($attempts - 1));
                usleep($delayMs * 1000);
            }
        }
    }
}
