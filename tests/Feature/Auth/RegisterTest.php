<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a user and returns a token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Demo User',
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]])
        ->assertJsonPath('data.user.name', 'Demo User')
        ->assertJsonPath('data.user.email', 'demo@example.com');

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();

    $user = User::query()->where('email', 'demo@example.com')->sole();

    expect($user->tokens()->count())->toBe(1);
});

it('never returns or stores the raw password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Demo User',
        'email' => 'demo@example.com',
        'password' => 'password',
    ]);

    $response->assertCreated();
    expect($response->json('data.user'))->not->toHaveKey('password');

    $user = User::query()->where('email', 'demo@example.com')->sole();

    expect($user->password)->not->toBe('password')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

it('rejects a duplicate email', function () {
    User::factory()->create(['email' => 'demo@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Demo User',
        'email' => 'demo@example.com',
        'password' => 'password',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['errors' => ['email']]]]);

    expect(User::query()->where('email', 'demo@example.com')->count())->toBe(1);
});

it('rejects a password under eight characters', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Demo User',
        'email' => 'demo@example.com',
        'password' => 'short',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['password']]]]);

    expect(User::query()->count())->toBe(0);
});

it('rejects missing fields', function () {
    $this->postJson('/api/v1/auth/register', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['name', 'email', 'password']]]]);
});
