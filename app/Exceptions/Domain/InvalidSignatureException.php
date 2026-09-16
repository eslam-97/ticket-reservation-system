<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A webhook arrived without a valid signature.
 *
 * Nobody's failure to recover from: a genuine provider signs correctly, and a
 * forgery should not be retried. The body is never echoed back.
 */
class InvalidSignatureException extends DomainException
{
    public function __construct()
    {
        parent::__construct(
            'invalid_signature',
            'The webhook signature could not be verified.',
            Response::HTTP_BAD_REQUEST,
        );
    }
}
