<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('acceptableIntervals')]
    public function test_accepts_a_valid_interval(string $timezone, string $startsAt, string $endsAt): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn($timezone);

        $this->postBooking($user, $room, $startsAt, $endsAt)
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseCount('bookings', 1);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function acceptableIntervals(): array
    {
        return [
            'час в москве' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00'],
            'то же мгновение в utc' => ['Europe/Moscow', '2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z'],
            'утро москвы, присланное как utc' => ['Europe/Moscow', '2026-11-02T06:00:00Z', '2026-11-02T07:00:00Z'],
            'час в новосибирске' => ['Asia/Novosibirsk', '2026-11-02T10:00:00+07:00', '2026-11-02T11:00:00+07:00'],
            'новосибирск со смещением москвы' => ['Asia/Novosibirsk', '2026-11-02T06:00:00+03:00', '2026-11-02T07:00:00+03:00'],
            'ровно 15 минут' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:15:00+03:00'],
            'ровно 4 часа до закрытия' => ['Europe/Moscow', '2026-11-02T16:00:00+03:00', '2026-11-02T20:00:00+03:00'],
            'открытие офиса' => ['Europe/Moscow', '2026-11-02T09:00:00+03:00', '2026-11-02T09:15:00+03:00'],
            'последние 15 минут' => ['Europe/Moscow', '2026-11-02T19:45:00+03:00', '2026-11-02T20:00:00+03:00'],
            'прошедшая дата' => ['Europe/Moscow', '2024-01-08T10:00:00+03:00', '2024-01-08T11:00:00+03:00'],
            'воскресенье' => ['Europe/Moscow', '2026-11-01T10:00:00+03:00', '2026-11-01T11:00:00+03:00'],
            'далёкое будущее' => ['Europe/Moscow', '2030-05-06T10:00:00+03:00', '2030-05-06T11:00:00+03:00'],
            'минуты без секунд' => ['Europe/Moscow', '2026-11-02T10:00+03:00', '2026-11-02T11:00+03:00'],
            'нулевые миллисекунды' => ['Europe/Moscow', '2026-11-02T10:00:00.000+03:00', '2026-11-02T11:00:00.000+03:00'],
            'смещение -03:30' => ['Europe/Moscow', '2026-11-02T09:30:00-03:30', '2026-11-02T10:30:00-03:30'],
            'смещение +0300' => ['Europe/Moscow', '2026-11-02T10:00:00+0300', '2026-11-02T11:00:00+0300'],
            'смещение +03' => ['Europe/Moscow', '2026-11-02T10:00:00+03', '2026-11-02T11:00:00+03'],
        ];
    }

    #[DataProvider('rejectedIntervals')]
    public function test_rejects_an_invalid_interval(
        string $timezone,
        string $startsAt,
        string $endsAt,
        string $field,
    ): void {
        $user = User::factory()->create();
        $room = $this->roomIn($timezone);

        $this->postBooking($user, $room, $startsAt, $endsAt)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('bookings', 0);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function rejectedIntervals(): array
    {
        return [
            'до открытия' => ['Europe/Moscow', '2026-11-02T08:45:00+03:00', '2026-11-02T09:00:00+03:00', 'starts_at'],
            'после закрытия' => ['Europe/Moscow', '2026-11-02T19:45:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at'],
            'четыре часа за пределами дня' => ['Europe/Moscow', '2026-11-02T16:15:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at'],
            'дольше четырёх часов' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T14:15:00+03:00', 'ends_at'],
            'короче 15 минут' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:14:00+03:00', 'ends_at'],
            'не на сетке 15 минут' => ['Europe/Moscow', '2026-11-02T10:07:00+03:00', '2026-11-02T10:22:00+03:00', 'starts_at'],
            'ненулевые секунды' => ['Europe/Moscow', '2026-11-02T10:00:30+03:00', '2026-11-02T11:00:30+03:00', 'starts_at'],
            'москва, время прислано как новосибирское' => ['Europe/Moscow', '2026-11-02T10:00:00+07:00', '2026-11-02T11:00:00+07:00', 'starts_at'],
            'вечер utc для москвы' => ['Europe/Moscow', '2026-11-02T18:00:00Z', '2026-11-02T19:00:00Z', 'starts_at'],
            'конец раньше начала' => ['Europe/Moscow', '2026-11-02T11:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at'],
            'старт ровно в закрытие' => ['Europe/Moscow', '2026-11-02T20:00:00+03:00', '2026-11-02T20:15:00+03:00', 'starts_at'],
            'ненулевые миллисекунды' => ['Europe/Moscow', '2026-11-02T10:00:00.001+03:00', '2026-11-02T11:00:00.001+03:00', 'starts_at'],
            'смещение +24:00' => ['Europe/Moscow', '2026-11-02T10:00:00+24:00', '2026-11-02T11:00:00+24:00', 'starts_at'],
            'смещение +24' => ['Europe/Moscow', '2026-11-02T10:00:00+24', '2026-11-02T11:00:00+24', 'starts_at'],
            'смещение +2400' => ['Europe/Moscow', '2026-11-02T10:00:00+2400', '2026-11-02T11:00:00+2400', 'starts_at'],
            'минуты смещения 99' => ['Europe/Moscow', '2026-11-02T10:00:00+03:99', '2026-11-02T11:00:00+03:99', 'starts_at'],
            'минуты смещения 99 без двоеточия' => ['Europe/Moscow', '2026-11-02T10:00:00+0399', '2026-11-02T11:00:00+0399', 'starts_at'],
            'без смещения' => ['Europe/Moscow', '2026-11-02T10:00:00', '2026-11-02T11:00:00', 'starts_at'],
            'равные начало и конец' => ['Europe/Moscow', '2026-11-02T10:00:00+03:00', '2026-11-02T10:00:00+03:00', 'ends_at'],
        ];
    }

    public function test_explains_working_hours_and_the_quarter_hour_grid(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T08:00:00+03:00', '2026-11-02T08:30:00+03:00')
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', 'Бронь должна целиком попадать в рабочее время офиса, с 09:00 до 20:00.');

        $this->postBooking($user, $room, '2026-11-02T10:07:00+03:00', '2026-11-02T11:07:00+03:00')
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.starts_at.0',
                'Начало и конец должны быть кратны 15 минутам по времени офиса: 10:00, 10:15, 10:30 или 10:45.',
            );
    }

    public function test_rejects_overlap_and_allows_back_to_back_bookings(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $this->postBooking($user, $room, '2026-11-02T10:30:00+03:00', '2026-11-02T11:30:00+03:00')
            ->assertUnprocessable()
            ->assertJsonPath('errors.starts_at.0', 'Комната уже занята в это время.');

        $this->postBooking($user, $room, '2026-11-02T11:00:00+03:00', '2026-11-02T12:00:00+03:00')
            ->assertCreated();

        $this->postBooking($user, $room, '2026-11-02T09:00:00+03:00', '2026-11-02T10:00:00+03:00')
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 3);
    }

    public function test_rejects_a_booking_inside_or_covering_an_existing_one(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00:00+03:00', '2026-11-02T12:00:00+03:00')
            ->assertCreated();

        $this->postBooking($user, $room, '2026-11-02T10:30:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertUnprocessable();

        $this->postBooking($user, $room, '2026-11-02T09:30:00+03:00', '2026-11-02T12:30:00+03:00')
            ->assertUnprocessable();

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_treats_the_same_instant_in_another_offset_as_overlap(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $this->postBooking($user, $room, '2026-11-02T07:00:00Z', '2026-11-02T08:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_allows_the_same_time_in_another_room(): void
    {
        $user = User::factory()->create();
        $first = $this->roomIn('Europe/Moscow');
        $second = $this->roomIn('Asia/Novosibirsk');

        $this->postBooking($user, $first, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $this->postBooking($user, $second, '2026-11-02T14:00:00+07:00', '2026-11-02T15:00:00+07:00')
            ->assertCreated();

        $this->assertDatabaseCount('bookings', 2);
    }

    public function test_stores_the_instant_in_utc(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_stores_utc_for_minute_precision_without_seconds(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00+03:00', '2026-11-02T11:00+03:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_stores_utc_for_zero_fractional_seconds(): void
    {
        $user = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($user, $room, '2026-11-02T10:00:00.000+03:00', '2026-11-02T11:00:00.000+03:00')
            ->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_ignores_user_id_from_the_body(): void
    {
        $author = User::factory()->create();
        $someoneElse = User::factory()->create();
        $room = $this->roomIn('Europe/Moscow');

        $this->postBooking($author, $room, '2026-11-02T10:00:00+03:00', '2026-11-02T11:00:00+03:00', [
            'user_id' => $someoneElse->id,
        ])->assertCreated()->assertJsonPath('user_id', $author->id);

        $this->assertDatabaseHas('bookings', ['user_id' => $author->id]);
        $this->assertDatabaseMissing('bookings', ['user_id' => $someoneElse->id]);
    }

    public function test_returns_validation_errors_for_missing_or_unknown_data(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);

        $this->postJson('/api/bookings', [
            'room_id' => 9999,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['room_id']);

        $this->postJson('/api/bookings', [
            'room_id' => '1 OR 1=1',
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['room_id']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_requires_authentication(): void
    {
        $room = $this->roomIn('Europe/Moscow');

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnauthorized();
    }

    public function test_database_rejects_an_overlapping_insert(): void
    {
        $room = $this->roomIn('Europe/Moscow');
        $user = User::factory()->create();

        DB::table('bookings')->insert([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Уже есть',
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($room, $user): void {
                DB::table('bookings')->insert([
                    'room_id' => $room->id,
                    'user_id' => $user->id,
                    'title' => 'Пересечение',
                    'starts_at' => '2026-11-02 07:30:00',
                    'ends_at' => '2026-11-02 08:30:00',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail('Пересекающаяся вставка должна была упасть на ограничении базы.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0] ?? null);
        }

        $this->assertDatabaseCount('bookings', 1);

        DB::table('bookings')->insert([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Впритык',
            'starts_at' => '2026-11-02 08:00:00',
            'ends_at' => '2026-11-02 09:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('bookings', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postBooking(User $user, Room $room, string $startsAt, string $endsAt, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/bookings', array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ], $overrides));
    }

    private function roomIn(string $timezone): Room
    {
        return Room::factory()->for(
            Office::factory()->create(['timezone' => $timezone]),
        )->create();
    }
}
