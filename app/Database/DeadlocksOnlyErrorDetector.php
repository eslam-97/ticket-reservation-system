<?php

namespace App\Database;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\ConcurrencyErrorDetector as LaravelDetector;
use Throwable;

/**
 * Narrows what `DB::transaction($fn, attempts: N)` is willing to retry.
 *
 * Laravel's own detector counts "Lock wait timeout exceeded" as a concurrency
 * error, so a transaction that waited out the full innodb_lock_wait_timeout
 * would be run again — and again — each time waiting the same amount. That is
 * precisely the behaviour the design rules out: retrying a lock wait doubles a
 * request that has already waited its full timeout, and it makes the
 * scheduler's "skip this row, pick it up next minute" impossible.
 *
 * A deadlock is different. MySQL has already rolled the transaction back and
 * chosen a victim, so the work never happened and retrying is both safe and
 * the documented contract.
 *
 * Bound in AppServiceProvider so the rule holds for every transaction in the
 * application rather than depending on each caller passing the right attempts.
 */
class DeadlocksOnlyErrorDetector implements ConcurrencyErrorDetector
{
    /** MySQL ER_LOCK_WAIT_TIMEOUT. */
    private const LOCK_WAIT_TIMEOUT = 1205;

    public function __construct(private readonly LaravelDetector $inner = new LaravelDetector) {}

    public function causedByConcurrencyError(Throwable $e): bool
    {
        if ($this->isLockWaitTimeout($e)) {
            return false;
        }

        return $this->inner->causedByConcurrencyError($e);
    }

    private function isLockWaitTimeout(Throwable $e): bool
    {
        $errorInfo = property_exists($e, 'errorInfo') ? $e->errorInfo : null;

        if (is_array($errorInfo) && ($errorInfo[1] ?? null) === self::LOCK_WAIT_TIMEOUT) {
            return true;
        }

        // A driver that did not populate errorInfo still says so in the text.
        return str_contains($e->getMessage(), 'Lock wait timeout exceeded');
    }
}
