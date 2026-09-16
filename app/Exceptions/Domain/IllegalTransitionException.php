<?php

namespace App\Exceptions\Domain;

use App\Exceptions\DomainException;
use BackedEnum;
use Symfony\Component\HttpFoundation\Response;

/**
 * A status write that the state machine does not allow.
 *
 * Reaching this is either an out-of-order provider event (refused and logged,
 * which is the documented behaviour) or a bug. Either way the write is
 * refused rather than applied.
 */
class IllegalTransitionException extends DomainException
{
    public static function for(string $subject, int|string $id, BackedEnum $from, BackedEnum $to): self
    {
        return new self(
            'illegal_transition',
            sprintf('%s %s cannot move from %s to %s.', $subject, $id, $from->value, $to->value),
            Response::HTTP_CONFLICT,
            [
                'subject' => $subject,
                'id' => (string) $id,
                'from' => $from->value,
                'to' => $to->value,
            ],
        );
    }
}
