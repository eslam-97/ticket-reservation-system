<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Payments\DTO\PaymentEvent;
use App\Payments\FakeWebhookSigner;
use App\Services\Payments\PaymentTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| When webhook processing throws
|--------------------------------------------------------------------------
|
| Section 9: the provider's retry loop owns this failure. We answer 500 and
| the transaction rolls back — webhook_events row included — so the
| redelivery is not mistaken for a duplicate of something we never applied.
| That last part is the whole point: recording the event and applying it must
| commit or roll back together.
|
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->actingAs($this->user)
        ->postJson("/api/v1/reservations/{$this->reservation->id}/checkout")
        ->assertOk();

    $this->attempt = Payment::query()->where('reservation_id', $this->reservation->id)->sole();
});

/**
 * Make PaymentTransitions blow up the way an unexpected bug would.
 */
function breakTransitions(): void
{
    app()->instance(PaymentTransitions::class, new class extends PaymentTransitions
    {
        public function apply(PaymentEvent $event, string $source, string $provider): void
        {
            throw new RuntimeException('processing exploded');
        }
    });
}

function postSignedWebhook(array $body)
{
    $signed = app(FakeWebhookSigner::class)->sign($body);

    return test()->call(
        'POST',
        '/api/v1/webhooks/payments/fake',
        [],
        [],
        [],
        [
            'HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $signed['body'],
    );
}

function successBody(Payment $attempt, string $eventId): array
{
    return [
        'id' => $eventId,
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) $attempt->id,
            'provider_ref' => $attempt->provider_ref,
            'amount' => (int) $attempt->amount,
            'currency' => $attempt->currency,
        ],
    ];
}

it('answers 500 and rolls the webhook event row back when processing throws', function () {
    breakTransitions();

    $response = postSignedWebhook(successBody($this->attempt, 'evt_explodes'));

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        // No trace, no message: the provider gets a 500 and nothing else.
        ->assertJsonPath('error.message', 'Something went wrong.');

    // The row must be gone, or the redelivery below would be swallowed as a
    // duplicate of an event that was never actually applied.
    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('applies the provider redelivery once the bug is gone', function () {
    // Fails the first delivery and then behaves, the way a transient bug
    // would. One object serves both requests, so the redelivery really does
    // travel the same path rather than a freshly rebuilt one.
    app()->instance(PaymentTransitions::class, new class extends PaymentTransitions
    {
        public int $calls = 0;

        public function apply(PaymentEvent $event, string $source, string $provider): void
        {
            if (++$this->calls === 1) {
                throw new RuntimeException('processing exploded');
            }

            parent::apply($event, $source, $provider);
        }
    });

    postSignedWebhook(successBody($this->attempt, 'evt_explodes'))->assertStatus(500);

    // The provider retries with backoff; the event id is the same one.
    postSignedWebhook(successBody($this->attempt, 'evt_explodes'))->assertNoContent();

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and(WebhookEvent::query()->count())->toBe(1)
        ->and(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('leaves no partial work behind when processing throws midway', function () {
    // Throwing after a write proves the rollback covers the whole unit, not
    // just the webhook_events insert.
    app()->instance(PaymentTransitions::class, new class extends PaymentTransitions
    {
        public function apply(PaymentEvent $event, string $source, string $provider): void
        {
            Payment::query()->whereKey($event->paymentId)->update(['failure_code' => 'half_written']);

            throw new RuntimeException('exploded after writing');
        }
    });

    postSignedWebhook(successBody($this->attempt, 'evt_partial'))->assertStatus(500);

    expect($this->attempt->refresh()->failure_code)->toBeNull()
        ->and(WebhookEvent::query()->count())->toBe(0);
});

it('does not answer 500 for an orphan event', function () {
    // An event we cannot place is acknowledged, not retried: a 500 here would
    // make the provider redeliver something we will never be able to apply.
    postSignedWebhook([
        'id' => 'evt_orphan_'.Str::ulid(),
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => (string) Str::ulid(),
            'provider_ref' => 'fake_cs_nothing',
            'amount' => 100,
            'currency' => 'EGP',
        ],
    ])->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1);
});
