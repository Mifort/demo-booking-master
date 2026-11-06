<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\OfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone'])]
class Office extends Model
{
    /** @use HasFactory<OfficeFactory> */
    use HasFactory;

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * Проверяет, что бронь целиком лежит в окне 09:00–20:00 местного дня офиса.
     *
     * Перед сравнением оба мгновения переводятся в часовой пояс этого офиса.
     * Конец ровно в 20:00 допустим.
     */
    public function fitsWorkingHours(CarbonInterface $start, CarbonInterface $end): bool
    {
        $startLocal = Carbon::parse($start)->timezone($this->timezone);
        $endLocal = Carbon::parse($end)->timezone($this->timezone);
        $opens = $startLocal->copy()->setTime(9, 0);
        $closes = $startLocal->copy()->setTime(20, 0);

        return $startLocal->greaterThanOrEqualTo($opens)
            && $endLocal->lessThanOrEqualTo($closes);
    }
}
