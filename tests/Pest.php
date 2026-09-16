<?php

use App\Payments\Drivers\FakeGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test runs against the real MySQL tickets_test database: this system
| depends on row locks, NOWAIT and stored generated columns, none of which
| behave correctly on SQLite. See docs/DESIGN_DECISIONS.md section 11.
|
| The database strategy is declared per file rather than globally. Most tests
| want RefreshDatabase's rolled-back transaction, but a test that needs a
| second connection to see its rows needs them committed, and the two traits
| cannot both be applied to the same test — Laravel would open a transaction
| and then TRUNCATE straight through it.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Architecture');

/*
|--------------------------------------------------------------------------
| Fake provider state
|--------------------------------------------------------------------------
|
| FakeGateway keeps its sessions in process-local statics, which outlive a
| test the way a real provider's records would not. Clearing them between
| tests keeps each one starting from a provider that has never seen us.
|
*/

pest()->beforeEach(fn () => FakeGateway::reset())->in('Feature', 'Unit', 'Architecture');
