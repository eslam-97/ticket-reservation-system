<?php

it('exposes the health endpoint used by the compose healthcheck', function () {
    $this->get('/up')->assertOk();
});

it('runs against the mysql tickets_test database', function () {
    expect(config('database.default'))->toBe('mysql')
        ->and(config('database.connections.mysql.database'))->toBe('tickets_test');
});
