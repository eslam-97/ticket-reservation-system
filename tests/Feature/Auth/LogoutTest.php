<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/**
 * Drop the guard's cached user.
 *
 * A real second request boots a fresh container and re-authenticates from the
 * Authorization header. Inside one test the app instance is reused, so the
 * guard would hand back the user it resolved for the previous call — and a
 * test that never re-authenticates cannot prove a token was revoked.
 */
function asANewRequest(): void
{
    Auth::forgetGuards();
}

it('revokes only the token that made the call', function () {
    $user = User::factory()->create();

    $current = $user->createToken('current')->plainTextToken;
    $other = $user->createToken('other')->plainTextToken;

    $this->withToken($current)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    // The other device is untouched.
    expect($user->tokens()->pluck('name')->all())->toBe(['other']);

    asANewRequest();

    $this->withToken($other)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('rejects a revoked token with the 401 envelope', function () {
    $user = User::factory()->create();
    $token = $user->createToken('api')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    asANewRequest();

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('rejects a request with no token at all', function () {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects a garbage token', function () {
    $this->withToken('not-a-real-token')
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('logs a user out with a token minted by the login endpoint', function () {
    User::factory()->create([
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'demo@example.com',
        'password' => 'password',
    ])->assertOk()->json('data.token');

    asANewRequest();

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

    asANewRequest();

    $this->withToken($token)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
