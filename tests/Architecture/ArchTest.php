<?php

/*
|--------------------------------------------------------------------------
| Architecture
|--------------------------------------------------------------------------
|
| Section 2's central claim is that the domain never imports a provider SDK,
| so swapping Stripe for Paymob is one class and one config value. That is a
| property of the *whole* codebase, and a code review cannot keep proving it
| as the codebase grows — these do.
|
*/

// One namespace per expectation: an array of namespaces silently passes,
// which a mutation check caught before these could give false comfort.
arch('actions never reach for the HTTP request')
    ->expect('App\Actions')
    ->not->toUse('Illuminate\Http\Request');

arch('services never reach for the HTTP request')
    ->expect('App\Services')
    ->not->toUse('Illuminate\Http\Request');

arch('the domain layer never reaches for the HTTP request')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Http\Request');

arch('actions never import the Stripe SDK')
    ->expect('App\Actions')
    ->not->toUse('Stripe');

arch('services never import the Stripe SDK')
    ->expect('App\Services')
    ->not->toUse('Stripe');

arch('the domain layer never imports the Stripe SDK')
    ->expect('App\Domain')
    ->not->toUse('Stripe');

arch('models never import the Stripe SDK')
    ->expect('App\Models')
    ->not->toUse('Stripe');

arch('the payment contract is provider-agnostic')
    ->expect('App\Contracts')
    ->not->toUse('Stripe');

arch('status enums never touch the database')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Support\Facades\DB');

/*
|--------------------------------------------------------------------------
| Rules Pest's arch expectations cannot phrase
|--------------------------------------------------------------------------
|
| These are file-level properties rather than namespace dependencies, so they
| are asserted by reading the source.
|
*/

/**
 * Every PHP file under a directory, with comments stripped so documentation
 * never reads as code.
 *
 * @return array<string, string> path => source
 */
function sourcesUnder(string $directory): array
{
    $sources = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $stripped = '';

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $sources[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())] = $stripped;
    }

    ksort($sources);

    return $sources;
}

it('confines the Stripe SDK to the one driver that implements it', function () {
    $importers = [];

    foreach (sourcesUnder('app') as $path => $source) {
        if (preg_match('/^use Stripe\\\\/m', $source) === 1) {
            $importers[] = str_replace('\\', '/', $path);
        }
    }

    // Exactly one file, so "adding a provider is one class" stays true.
    expect($importers)->toBe(['app/Payments/Drivers/StripeGateway.php']);
});

it('reads configuration only through config(), never env()', function () {
    $offenders = [];

    foreach (sourcesUnder('app') as $path => $source) {
        // `env()` outside a config file returns null once config is cached,
        // which fails in production and nowhere else.
        if (preg_match('/(?<![\w>$])env\s*\(/', $source) === 1) {
            $offenders[] = str_replace('\\', '/', $path);
        }
    }

    expect($offenders)->toBe([], 'env() belongs in config/ only: '.implode(', ', $offenders));
});

it('mentions sqlite nowhere in the application or the suite', function () {
    // These two name it only in order to forbid it, so they are the one place
    // the word is allowed to appear.
    $policing = [
        'tests/Architecture/ArchTest.php',
        'tests/Unit/ConfigTest.php',
    ];

    $offenders = [];

    foreach (['app', 'tests'] as $directory) {
        foreach (sourcesUnder($directory) as $path => $source) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

            if (in_array($path, $policing, true)) {
                continue;
            }

            if (stripos($source, 'sqlite') !== false) {
                $offenders[] = $path;
            }
        }
    }

    // SQLite silently no-ops lockForUpdate() and treats generated columns
    // differently, so a single reference is a foothold for the whole suite to
    // stop testing what it claims to.
    expect($offenders)->toBe([], 'sqlite is forbidden: '.implode(', ', $offenders));
});

it('declares fillable on every model and guarded on none', function () {
    $missingFillable = [];
    $usesGuarded = [];

    foreach (sourcesUnder('app/Models') as $path => $source) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

        if (! str_contains($source, 'protected $fillable')) {
            $missingFillable[] = $path;
        }

        if (str_contains($source, '$guarded')) {
            $usesGuarded[] = $path;
        }
    }

    // Section 8: mass assignment is opt-in, one explicit list per model.
    expect($missingFillable)->toBe([], 'models without $fillable: '.implode(', ', $missingFillable))
        ->and($usesGuarded)->toBe([], 'models using $guarded: '.implode(', ', $usesGuarded));
});

/*
|--------------------------------------------------------------------------
| Test-suite hygiene
|--------------------------------------------------------------------------
*/

it('makes every DatabaseTruncation file clean up after itself', function () {
    $offenders = [];

    foreach (sourcesUnder('tests') as $path => $source) {
        if (! preg_match('/uses\s*\(\s*DatabaseTruncation::class/', $source)) {
            continue;
        }

        // The trait clears tables before each of its own tests and never
        // after the last, and suite order is not stable across environments —
        // so without this, committed rows leak into whatever sorts next.
        if (! preg_match('/afterEach\s*\(.*truncateDatabaseTables/s', $source)) {
            $offenders[] = str_replace('\\', '/', $path);
        }
    }

    expect($offenders)->toBe(
        [],
        'these commit rows but never clean up after the last test: '.implode(', ', $offenders),
    );
});

it('keeps every test pointed at the tickets_test database', function () {
    expect(config('database.default'))->toBe('mysql')
        ->and(config('database.connections.mysql.database'))->toBe('tickets_test')
        ->and(config('database.connections.mysql.driver'))->toBe('mysql');
});
