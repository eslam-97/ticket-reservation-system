<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class NotAuthorized extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'This action is unauthorized.', array $details = [])
    {
        parent::__construct('forbidden', $message, Response::HTTP_FORBIDDEN, $details);
    }
}
