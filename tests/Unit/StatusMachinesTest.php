<?php

use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;

/*
|--------------------------------------------------------------------------
| State machines
|--------------------------------------------------------------------------
|
| Both machines are asserted exhaustively: every ordered pair of cases is
| checked, so adding a case or an edge without updating the allow-list below
| fails here rather than somewhere downstream.
|
*/

/** Every edge the reservation machine permits; everything else must be refused. */
const RESERVATION_EDGES = [
    ['pending', 'confirmed'],
    ['pending', 'expired'],
    ['pending', 'cancelled'],
    ['expired', 'confirmed'],
];

/** Every edge the payment machine permits. Strictly forward-only. */
const PAYMENT_EDGES = [
    ['initiated', 'succeeded'],
    ['initiated', 'failed'],
    ['initiated', 'expired'],
    ['initiated', 'cancelled'],
    ['initiated', 'mismatched'],
    ['succeeded', 'refund_pending'],
    ['refund_pending', 'refunded'],
];

it('permits exactly the listed reservation transitions', function () {
    foreach (ReservationStatus::cases() as $from) {
        foreach (ReservationStatus::cases() as $to) {
            $allowed = in_array([$from->value, $to->value], RESERVATION_EDGES, true);

            expect($from->canTransitionTo($to))->toBe(
                $allowed,
                sprintf('%s → %s should be %s', $from->value, $to->value, $allowed ? 'allowed' : 'refused'),
            );
        }
    }
});

it('permits exactly the listed payment transitions', function () {
    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            $allowed = in_array([$from->value, $to->value], PAYMENT_EDGES, true);

            expect($from->canTransitionTo($to))->toBe(
                $allowed,
                sprintf('%s → %s should be %s', $from->value, $to->value, $allowed ? 'allowed' : 'refused'),
            );
        }
    }
});

it('never lets a reservation transition to itself', function () {
    foreach (ReservationStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('never lets a payment transition to itself', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->canTransitionTo($status))->toBeFalse();
    }
});

it('marks confirmed and cancelled as the terminal reservation states', function () {
    expect(ReservationStatus::Confirmed->isTerminal())->toBeTrue()
        ->and(ReservationStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(ReservationStatus::Pending->isTerminal())->toBeFalse()
        // Expired is not terminal: confirmLate is the one named backward edge.
        ->and(ReservationStatus::Expired->isTerminal())->toBeFalse();
});

it('marks failed, expired, cancelled, mismatched and refunded as terminal payment states', function () {
    expect(PaymentStatus::Failed->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Expired->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Mismatched->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Refunded->isTerminal())->toBeTrue()
        ->and(PaymentStatus::Initiated->isTerminal())->toBeFalse()
        ->and(PaymentStatus::Succeeded->isTerminal())->toBeFalse()
        ->and(PaymentStatus::RefundPending->isTerminal())->toBeFalse();
});

it('leaves every terminal state with no outgoing edges', function () {
    foreach (ReservationStatus::cases() as $status) {
        if ($status->isTerminal()) {
            foreach (ReservationStatus::cases() as $to) {
                expect($status->canTransitionTo($to))->toBeFalse();
            }
        }
    }

    foreach (PaymentStatus::cases() as $status) {
        if ($status->isTerminal()) {
            foreach (PaymentStatus::cases() as $to) {
                expect($status->canTransitionTo($to))->toBeFalse();
            }
        }
    }
});
