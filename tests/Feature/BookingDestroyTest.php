<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_a_booking(): void
    {
        $user = User::factory()->create();
        $booking = Booking::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/bookings/'.$booking->id)->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_another_user_cannot_cancel_a_booking(): void
    {
        $booking = Booking::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'Отменить бронь может только её автор.');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_missing_booking_returns_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/9999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)->assertUnauthorized();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
