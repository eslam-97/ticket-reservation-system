<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Error envelope
|--------------------------------------------------------------------------
|
| Every failure leaves the API in the same shape, whoever raised it: a domain
| exception, the validator, the router, or an unhandled bug.
|
*/

it('returns the envelope for an unknown route', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('returns the envelope for an unknown route outside the api prefix', function () {
    // API-only: even here the answer is JSON, never an HTML error page.
    $this->getJson('/nope')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertHeader('content-type', 'application/json');
});

it('returns the envelope with details.errors for a validation failure', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email' => 'not-an-email',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure([
            'error' => ['code', 'message', 'details' => ['errors' => ['name', 'email', 'password']]],
        ]);

    expect($response->json('error.details.errors.email'))->toBeArray()->not->toBeEmpty();
});

it('returns the envelope for a wrong http method', function () {
    $this->getJson('/api/v1/auth/login')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('renders details as an object even when empty', function () {
    // Clients can read error.details.* without first checking whether it came
    // back as [] instead of {}.
    $response = $this->getJson('/api/v1/does-not-exist');

    expect($response->json('error.details'))->toBe([]);
    expect(json_decode($response->getContent(), false)->error->details)->toBeObject();
});

it('never renders an html error page for an authenticated route', function () {
    $response = $this->postJson('/api/v1/auth/logout');

    $response->assertStatus(401)->assertHeader('content-type', 'application/json');
    expect($response->getContent())->not->toContain('<!DOCTYPE');
});

it('keeps the envelope shape for a successful request', function () {
    $user = User::factory()->create();

    // Success is not enveloped: only failures carry "error".
    $response = $this->withToken($user->createToken('api')->plainTextToken)
        ->postJson('/api/v1/auth/logout');

    $response->assertNoContent();
    expect($response->getContent())->toBe('');
});
