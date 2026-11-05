<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_id', 'user_id', 'starts_at', 'ends_at', 'title'])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Брони комнаты, пересекающиеся с полуинтервалом [start, end).
     * Стык 10:00–11:00 и 11:00–12:00 пересечением не является.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeOverlapping(Builder $query, int $roomId, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query
            ->where('room_id', $roomId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
