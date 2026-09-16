<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SeatResource;
use App\Models\Event;
use App\Services\Seats\SeatAvailability;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventSeatController extends Controller
{
    public function __construct(private readonly SeatAvailability $availability) {}

    /**
     * The whole map in one response. Seat maps are not paginated: a client
     * drawing a seating chart needs every seat at once, and the seeder keeps
     * events to a few hundred seats.
     */
    public function index(Event $event): AnonymousResourceCollection
    {
        return SeatResource::collection($this->availability->mapFor($event));
    }
}
