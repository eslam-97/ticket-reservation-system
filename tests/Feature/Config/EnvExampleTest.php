<?php

/*
|--------------------------------------------------------------------------
| .env.example carries no real credentials
|--------------------------------------------------------------------------
|
| Section 8: zero real credentials in the repository, and the fake driver is
| the default. This file is the first thing a reviewer copies, so a secret
| that leaked into it would be committed, cloned and deployed by everyone who
| followed the README.
|
*/

/**
 * Parse a dotenv file into key => value, ignoring comments and blanks.
 *
 * @return array<string, string>
 */
function parseEnvFile(string $path): array
{
    expect(file_exists($path))->toBeTrue("{$path} is missing");

    $values = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $values[trim($key)] = trim(trim($value), '"\'');
    }

    return $values;
}

beforeEach(function () {
    $this->env = parseEnvFile(base_path('.env.example'));
});

it('ships no stripe credentials', function () {
    // Present so a deployment knows the names, empty so nothing real is here.
    expect($this->env)->toHaveKeys(['STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET'])
        ->and($this->env['STRIPE_SECRET_KEY'])->toBe('')
        ->and($this->env['STRIPE_WEBHOOK_SECRET'])->toBe('');
});

it('ships an obvious placeholder for the fake webhook secret', function () {
    // The fake driver signs for real, so it needs *a* secret — but one that
    // announces itself as needing replacement.
    expect($this->env['FAKE_WEBHOOK_SECRET'])->toBe('change-me-fake-secret')
        ->and($this->env['FAKE_WEBHOOK_SECRET'])->toContain('change-me');
});

it('ships no application key', function () {
    // Generated per install by `make up`; a shared key would make every
    // deployment's encrypted payloads readable by every other.
    expect($this->env['APP_KEY'])->toBe('');
});

it('defaults to the fake payment driver', function () {
    expect($this->env['PAYMENTS_DRIVER'])->toBe('fake');
});

it('keeps debug off and points at mysql', function () {
    expect($this->env['APP_DEBUG'])->toBe('false')
        ->and($this->env['DB_CONNECTION'])->toBe('mysql')
        ->and($this->env['DB_DATABASE'])->toBe('tickets');
});

it('contains nothing that looks like a live credential', function () {
    $suspicious = [
        'sk_live_', 'pk_live_', 'rk_live_', 'whsec_',
        'AKIA', 'ASIA',
        '-----BEGIN',
        'ghp_', 'github_pat_',
        'xoxb-', 'xoxp-',
    ];

    $contents = file_get_contents(base_path('.env.example'));

    foreach ($suspicious as $marker) {
        expect($contents)->not->toContain($marker, "'{$marker}' looks like a real credential");
    }
});

it('gives every value a placeholder rather than a plausible secret', function () {
    // The only non-empty credentials here are local-only defaults that the
    // Compose file uses verbatim, so they are deliberately guessable.
    $localOnly = [
        'DB_PASSWORD' => 'secret',
        'DB_USERNAME' => 'tickets',
        'FAKE_WEBHOOK_SECRET' => 'change-me-fake-secret',
    ];

    foreach ($localOnly as $key => $expected) {
        expect($this->env[$key])->toBe($expected);
    }
});

it('keeps .env.testing in step, differing only where it must', function () {
    $testing = parseEnvFile(base_path('.env.testing'));

    expect($testing['APP_ENV'])->toBe('testing')
        ->and($testing['DB_DATABASE'])->toBe('tickets_test')
        ->and($testing['DB_CONNECTION'])->toBe('mysql')
        ->and($testing['STRIPE_SECRET_KEY'])->toBe('')
        ->and($testing['STRIPE_WEBHOOK_SECRET'])->toBe('');

    // Committed on purpose, so it must hold nothing worth hiding. Its APP_KEY
    // is test-only, which is why it may be non-empty here and not in the
    // example.
    $shared = array_diff_key($this->env, array_flip(['APP_ENV', 'DB_DATABASE', 'APP_KEY']));

    foreach ($shared as $key => $value) {
        expect($testing)->toHaveKey($key)
            ->and($testing[$key])->toBe($value, "{$key} drifted between .env.example and .env.testing");
    }
});

it('is the file .gitignore actually keeps', function () {
    $gitignore = file_get_contents(base_path('.gitignore'));

    // .env is ignored; .env.example and .env.testing are not.
    expect($gitignore)->toContain("\n.env\n")
        ->not->toContain("\n.env.example")
        ->not->toContain("\n.env.testing");
});
