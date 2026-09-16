<?php

namespace App\Payments\DTO;

/**
 * What a provider event means to us, stripped of provider vocabulary.
 */
enum PaymentEventType
{
    case Succeeded;
    case Failed;
    case Expired;

    /**
     * A provider event we do not act on.
     *
     * Providers send far more than the handful of things this system cares
     * about. Unknown events must still be acknowledged — refusing them only
     * makes the provider retry forever — but they never move a payment.
     */
    case Unknown;
}
