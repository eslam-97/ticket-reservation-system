<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\StartCheckout;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreReservationRequest;
use App\Http\Resources\Api\V1\ReservationResource;
use App\Models\Event;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request, CreateReservation $action): JsonResponse
    {
        // The request rules already proved this event exists and is still
        // selling, so this is a plain lookup rather than a guard.
        $event = Event::query()->findOrFail($request->integer('event_id'));

        $result = $action->handle($request->user(), $event, $request->seatIds());

        return ReservationResource::make($result->reservation->load(ReservationResource::RELATIONS))
            ->response()
            // 201 for a new hold; 200 when the caller's own identical hold was
            // handed back, which is what a retried request should see.
            ->setStatusCode($result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Request $request, Reservation $reservation): ReservationResource
    {
        Gate::authorize('view', $reservation);

        return ReservationResource::make($reservation->load(ReservationResource::RELATIONS));
    }

    public function checkout(Request $request, Reservation $reservation, StartCheckout $action): JsonResponse
    {
        Gate::authorize('checkout', $reservation);

        $result = $action->handle($request->user(), $reservation);

        return response()->json([
            'data' => [
                'payment_id' => $result->paymentId,
                'checkout_url' => $result->checkoutUrl,
                'provider_ref' => $result->providerRef,
                'session_expires_at' => $result->sessionExpiresAt->utc()->toIso8601String(),
            ],
        ]);
    }

    public function destroy(Request $request, Reservation $reservation, CancelReservation $action): ReservationResource
    {
        Gate::authorize('cancel', $reservation);

        $cancelled = $action->handle($request->user(), $reservation);

        return ReservationResource::make($cancelled->load(ReservationResource::RELATIONS));
    }
}
