<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventResource;
use App\Models\Event;
use App\Services\Seats\SeatAvailability;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class EventController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly SeatAvailability $availability) {}

    public function index(): AnonymousResourceCollection
    {
        $events = Event::query()->orderBy('starts_at')->paginate(self::PER_PAGE);

        // One counts query for the whole page, not one per event.
        $this->availability->attachCounts($events->getCollection());

        return EventResource::collection($events);
    }

    public function show(Event $event): EventResource
    {
        $this->availability->attachCounts(new Collection([$event]));

        return EventResource::make($event);
    }
}
