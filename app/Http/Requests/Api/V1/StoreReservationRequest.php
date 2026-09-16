<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Event;
use App\Models\Seat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', Rule::exists('events', 'id')],
            'seat_ids' => ['required', 'array', 'min:1', 'max:'.config('reservations.max_seats')],
            'seat_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Rules that need the event and seats loaded. Each one owns an error key,
     * so the client is told which field to fix.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // The shape rules above must pass first, or these lookups
                // would run on garbage and bury the real error.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $event = Event::query()->find($this->integer('event_id'));

                if (! $event instanceof Event) {
                    return;
                }

                // Holds can only start while the event is still ahead of us.
                // A hold that outlives its event is handled by confirmLate,
                // not by opening a new one.
                if ($event->starts_at <= now()) {
                    $validator->errors()->add('event_id', 'Sales for this event have closed.');
                }

                $requested = array_map(intval(...), $this->input('seat_ids', []));

                $belonging = Seat::query()
                    ->where('event_id', $event->getKey())
                    ->whereIn('id', $requested)
                    ->pluck('id')
                    ->map(intval(...))
                    ->all();

                foreach (array_diff($requested, $belonging) as $seatId) {
                    $validator->errors()->add('seat_ids', "Seat {$seatId} does not belong to this event.");
                }
            },
        ];
    }

    /**
     * @return list<int>
     */
    public function seatIds(): array
    {
        return array_map(intval(...), $this->validated('seat_ids'));
    }
}
