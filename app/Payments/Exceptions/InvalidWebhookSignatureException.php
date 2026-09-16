<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The webhook body did not carry a valid signature for this provider.
 *
 * Thrown before the body is parsed: nothing unverified is ever interpreted.
 * There is no bypass flag anywhere in this system, so tests and the
 * documented curl walkthrough exercise the same signature path production
 * does.
 */
class InvalidWebhookSignatureException extends RuntimeException {}
