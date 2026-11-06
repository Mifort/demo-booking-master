<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Определяет, кто имеет право выполнять действия с бронью.
 */
class BookingPolicy
{
    /**
     * Разрешает отмену только пользователю, который создал бронь.
     */
    public function delete(User $user, Booking $booking): Response
    {
        if ($user->id === $booking->user_id) {
            return Response::allow();
        }

        return Response::deny('Отменить бронь может только её автор.');
    }
}
