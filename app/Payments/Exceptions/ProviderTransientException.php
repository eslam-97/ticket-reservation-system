<?php

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * The provider call failed in a way that may succeed on a retry, or whose
 * outcome we simply do not know: a timeout, a connection error, a 429, a 5xx.
 *
 * The unknown-outcome case is always treated as transient, because the safe
 * assumption is that the provider may have done the work. The attempt stays
 * `initiated` and the next call replays the same idempotency key.
 */
class ProviderTransientException extends RuntimeException {}
