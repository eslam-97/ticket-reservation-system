<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class NotAuthenticated extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'Unauthenticated.', array $details = [])
    {
        parent::__construct('unauthenticated', $message, Response::HTTP_UNAUTHORIZED, $details);
    }
}
