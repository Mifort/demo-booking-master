<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        try {
            $booking = DB::transaction(function () use ($request): Booking {
                $room = $request->room();
                $lockedRoom = Room::query()->whereKey($room->id)->lockForUpdate()->first();

                if ($lockedRoom === null) {
                    throw ValidationException::withMessages([
                        'room_id' => 'Комната не найдена.',
                    ]);
                }

                $startsAt = $request->startsAt();
                $endsAt = $request->endsAt();

                $overlaps = Booking::query()
                    ->overlapping($room->id, $startsAt, $endsAt)
                    ->exists();

                if ($overlaps) {
                    $this->throwOverlap();
                }

                return Booking::query()->create([
                    'room_id' => $room->id,
                    'user_id' => $request->user()->id,
                    'title' => $request->validated('title'),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isOverlapViolation($exception)) {
                throw $exception;
            }

            $this->throwOverlap();
        }

        Log::channel('bookings')->info('Booking created', [
            'booking_id' => $booking->id,
        ]);

        return response()->json($booking, 201);
    }

    public function destroy(Booking $booking): JsonResponse|Response
    {
        $decision = Gate::inspect('delete', $booking);

        if ($decision->denied()) {
            return response()->json([
                'message' => $decision->message(),
            ], 403);
        }

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }

    private function throwOverlap(): never
    {
        throw ValidationException::withMessages([
            'starts_at' => 'Комната уже занята в это время.',
        ]);
    }

    private function isOverlapViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23P01';
    }
}
