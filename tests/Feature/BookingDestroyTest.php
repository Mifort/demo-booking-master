<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Проверяет отмену брони через DELETE API.
 */
class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Автор может отменить свою бронь и получает ответ 204.
     */
    public function test_author_can_cancel_a_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    /**
     * Посторонний получает 403 без стектрейса, а бронь остаётся в базе.
     */
    public function test_another_user_cannot_cancel_a_booking(): void
    {
        $booking = Booking::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.')
            ->assertJsonMissingPath('exception');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    /**
     * Несуществующая бронь возвращает 404 через route model binding.
     */
    public function test_missing_booking_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/9999')->assertNotFound();
    }

    /**
     * Неаутентифицированный запрос получает 401 и не удаляет бронь.
     */
    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
