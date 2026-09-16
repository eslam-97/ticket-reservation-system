<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hold length
    |--------------------------------------------------------------------------
    |
    | expires_at = min(now + hold_minutes, event.starts_at). One column, one
    | clamp: a hold started 15 minutes before the event is 15 minutes long.
    | The hold is never extended.
    |
    */

    'hold_minutes' => (int) env('RESERVATION_HOLD_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Maximum seats per reservation
    |--------------------------------------------------------------------------
    */

    'max_seats' => (int) env('RESERVATION_MAX_SEATS', 10),

];
