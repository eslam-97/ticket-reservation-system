<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);
});

it('returns a token for valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);

    $response->assertOk()->assertJsonStructure(['data' => ['token']]);

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($this->user->tokens()->count())->toBe(1);
});

it('rejects a wrong password as a validation error on email', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'demo@example.com',
        'password' => 'not-the-password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.errors.email', ['Invalid credentials.']);

    expect($this->user->tokens()->count())->toBe(0);
});

it('gives an unknown email the same answer as a wrong password', function () {
    // Identical response, so the endpoint cannot enumerate registered users.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.errors.email', ['Invalid credentials.']);
});

it('rejects missing fields', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['email', 'password']]]]);
});

it('throttles a sixth login attempt from the same ip within a minute', function () {
    // The real throttle middleware, not a fake: five attempts are allowed and
    // the sixth is refused before the credentials are even looked at.
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@example.com',
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'too_many_requests')
        // Pinned so the dedicated throttle arm cannot silently fall through
        // to the generic HTTP arm, which would word this differently.
        ->assertJsonPath('error.message', 'Too many requests.')
        ->assertHeader('Retry-After');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);

    // Refused before authentication: correct credentials issued no token.
    expect($this->user->tokens()->count())->toBe(0);
});
