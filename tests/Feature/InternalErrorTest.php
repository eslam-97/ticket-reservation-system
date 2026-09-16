<?php

use Illuminate\Support\Facades\Route;

it('renders an unhandled exception as a 500 envelope with no stack trace', function () {
    config(['app.debug' => false]);

    Route::post('/api/v1/__boom', fn () => throw new RuntimeException('database credentials are hunter2'));

    $response = $this->postJson('/api/v1/__boom');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.message', 'Something went wrong.');

    // Neither the real message nor a trace reaches the client.
    expect($response->getContent())
        ->not->toContain('hunter2')
        ->not->toContain('RuntimeException')
        ->not->toContain('vendor/laravel')
        ->and($response->json('error.details'))->toBe([]);

    // No "trace" key anywhere in the body, at any depth.
    $body = $response->json();

    expect($body)->toBe(['error' => ['code' => 'internal_error', 'message' => 'Something went wrong.', 'details' => []]])
        ->and(array_keys($body))->toBe(['error'])
        ->and($response->getContent())->not->toContain('trace')
        ->not->toContain('"file"')
        ->not->toContain('"line"');
});

it('keeps debug off wherever the app actually runs', function () {
    // docker-compose pins APP_DEBUG=false in `environment:` for both PHP
    // services, which overrides env_file — so a stray APP_DEBUG=true in
    // someone's local .env cannot turn stack traces back on.
    $compose = file_get_contents(base_path('docker-compose.yml'));

    expect(substr_count($compose, 'APP_DEBUG: "false"'))->toBe(2)
        ->and(file_get_contents(base_path('.env.example')))->toContain('
APP_DEBUG=false');
});

it('includes diagnostics only when APP_DEBUG is on', function () {
    config(['app.debug' => true]);

    Route::post('/api/v1/__boom', fn () => throw new RuntimeException('kaboom'));

    $this->postJson('/api/v1/__boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.details.exception', RuntimeException::class)
        ->assertJsonPath('error.details.message', 'kaboom');
});
