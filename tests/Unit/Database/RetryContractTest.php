<?php

use App\Database\DeadlocksOnlyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Deliberately no RefreshDatabase. Its outer transaction would make every
 * DB::transaction() below a *nested* one, and Laravel never retries those:
 * MySQL rolls the whole transaction back on a deadlock, so replaying to a
 * savepoint would be wrong. Production transactions are top-level, so these
 * must be too. Nothing here writes a row.
 */

/*
|--------------------------------------------------------------------------
| What a transaction retry means
|--------------------------------------------------------------------------
|
| Section 4: `DB::transaction($fn, attempts: 3)` retries deadlocks (1213) and
| nothing else. A lock-wait timeout (1205) is never retried in-process,
| because the transaction has already waited out the full timeout and running
| it again just spends another one.
|
| A genuine 1213 needs two connections racing in opposite lock orders, which
| no single-threaded test can stage — and under a total lock order it should
| never happen at all. What *is* testable is the contract the retry wrapper
| applies, so that is what these assert: the classification, and the number of
| attempts each class actually gets.
|
*/

/**
 * A QueryException shaped like the one MySQL produces for `$message`.
 */
function mysqlError(string $message, int $code): QueryException
{
    $previous = new PDOException($message);
    $previous->errorInfo = ['HY000', $code, $message];

    return new QueryException('mysql', 'select 1', [], $previous);
}

function deadlock(): QueryException
{
    return mysqlError('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction', 1213);
}

function lockWaitTimeout(): QueryException
{
    return mysqlError('SQLSTATE[HY000]: Lock wait timeout exceeded; try restarting transaction', 1205);
}

/*
|--------------------------------------------------------------------------
| The classification
|--------------------------------------------------------------------------
*/

it('classifies a deadlock as worth retrying', function () {
    // MySQL has already rolled the whole transaction back and picked a
    // victim, so the work never happened and running it again is safe.
    expect((new DeadlocksOnlyErrorDetector)->causedByConcurrencyError(deadlock()))->toBeTrue();
});

it('classifies a lock-wait timeout as not worth retrying', function () {
    expect((new DeadlocksOnlyErrorDetector)->causedByConcurrencyError(lockWaitTimeout()))->toBeFalse();
});

it('recognises a lock-wait timeout from the message when errorInfo is absent', function () {
    // Some drivers do not populate errorInfo; the rule must still hold.
    $bare = new QueryException(
        'mysql',
        'select 1',
        [],
        new PDOException('SQLSTATE[HY000]: Lock wait timeout exceeded; try restarting transaction'),
    );

    expect((new DeadlocksOnlyErrorDetector)->causedByConcurrencyError($bare))->toBeFalse();
});

it('leaves every other failure alone', function () {
    $detector = new DeadlocksOnlyErrorDetector;

    expect($detector->causedByConcurrencyError(mysqlError('SQLSTATE[23000]: Duplicate entry', 1062)))->toBeFalse()
        ->and($detector->causedByConcurrencyError(new RuntimeException('something else')))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The number of attempts each class actually gets
|--------------------------------------------------------------------------
*/

it('runs a deadlocked transaction three times before giving up', function () {
    $runs = 0;

    expect(function () use (&$runs) {
        DB::transaction(function () use (&$runs): void {
            $runs++;

            throw deadlock();
        }, attempts: 3);
    })->toThrow(QueryException::class);

    // Section 9: "Transaction retried up to 3 times".
    expect($runs)->toBe(3);
});

it('runs a lock-wait-timed-out transaction exactly once', function () {
    $runs = 0;

    expect(function () use (&$runs) {
        DB::transaction(function () use (&$runs): void {
            $runs++;

            throw lockWaitTimeout();
        }, attempts: 3);
    })->toThrow(QueryException::class);

    // The caller decides what to do — the sweep skips the row, the webhook
    // returns 500 and lets the provider retry — but it is never re-run here.
    expect($runs)->toBe(1);
});

it('does not retry an ordinary failure', function () {
    $runs = 0;

    expect(function () use (&$runs) {
        DB::transaction(function () use (&$runs): void {
            $runs++;

            throw new RuntimeException('business rule');
        }, attempts: 3);
    })->toThrow(RuntimeException::class);

    expect($runs)->toBe(1);
});

it('commits a deadlocked transaction that succeeds on a later attempt', function () {
    $runs = 0;

    $result = DB::transaction(function () use (&$runs): string {
        $runs++;

        if ($runs < 3) {
            throw deadlock();
        }

        return 'committed';
    }, attempts: 3);

    expect($result)->toBe('committed')->and($runs)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Every write transaction opts into the retry
|--------------------------------------------------------------------------
*/

it('passes attempts: 3 to every DB::transaction in the application', function () {
    $offenders = [];

    foreach (appPhpFiles() as $file) {
        // Comments stripped first: a docblock that merely mentions
        // DB::transaction() is not a call site.
        $source = stripPhpComments(file_get_contents($file));

        // Closures make a regex over a whole call fragile, so this counts
        // call sites against the named arguments that follow them.
        $calls = substr_count($source, 'DB::transaction(');
        $withAttempts = substr_count($source, 'attempts: 3');

        if ($calls > 0 && $withAttempts < $calls) {
            $offenders[] = basename($file)." ({$calls} calls, {$withAttempts} with attempts: 3)";
        }
    }

    expect($offenders)->toBe([], 'every write transaction must opt into the deadlock retry: '.implode(', ', $offenders));
});

/**
 * @return list<string>
 */
function appPhpFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Source with every comment removed, so documentation never reads as code.
 */
function stripPhpComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

it('never retries a nested transaction, even on a deadlock', function () {
    // Worth pinning because it explains why no feature test can prove the
    // retry: under RefreshDatabase every transaction is nested, and Laravel
    // converts a nested deadlock into DeadlockException rather than replaying
    // it — MySQL has rolled back the whole transaction, savepoint and all.
    $runs = 0;

    DB::beginTransaction();

    try {
        expect(function () use (&$runs) {
            DB::transaction(function () use (&$runs): void {
                $runs++;

                throw deadlock();
            }, attempts: 3);
        })->toThrow(DeadlockException::class);
    } finally {
        DB::rollBack();
    }

    expect($runs)->toBe(1);
});
