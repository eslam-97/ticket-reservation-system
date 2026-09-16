<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

class NotFound extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(string $message = 'Resource not found.', array $details = [])
    {
        parent::__construct('not_found', $message, Response::HTTP_NOT_FOUND, $details);
    }

    public static function reservation(string $id): self
    {
        return new self('Reservation not found.', ['reservation_id' => $id]);
    }
}
