<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Проверяет данные перед созданием брони.
 *
 * Базовые ошибки формата и бизнес-правил возвращаются клиенту как 422
 * до выполнения BookingController::store().
 */
class StoreBookingRequest extends FormRequest
{
    private const MIN_DURATION_SECONDS = 15 * 60;

    private const MAX_DURATION_SECONDS = 4 * 60 * 60;

    private const STEP_SECONDS = 15 * 60;

    /**
     * ISO 8601 со смещением: минуты времени обязательны, секунды и дробная часть опциональны.
     * Смещение — Z, ±HH:mm, ±HHmm или ±HH; диапазон часов и минут проверяется отдельно.
     */
    private const ISO8601_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|([+-])(\d{2})(?::(\d{2})|(\d{2}))?)$/';

    private ?Room $room = null;

    /**
     * Создавать бронь может любой пользователь, уже прошедший auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Описывает базовые правила полей запроса.
     *
     * @return array<string, array<int, ValidationRule|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', $this->iso8601WithOffsetRule(), 'date'],
            'ends_at' => ['required', 'string', $this->iso8601WithOffsetRule(), 'date', 'after:starts_at'],
        ];
    }

    /**
     * Возвращает понятные клиенту сообщения базовой валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.integer' => 'Комната указана неверно.',
            'room_id.exists' => 'Комната не найдена.',
            'title.required' => 'Укажите название.',
            'title.string' => 'Название должно быть строкой.',
            'title.max' => 'Название не должно быть длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date' => 'Время начала указано неверно.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date' => 'Время окончания указано неверно.',
            'ends_at.after' => 'Время окончания должно быть позже начала.',
        ];
    }

    /**
     * После базовой валидации проверяет сетку, длительность и рабочее время офиса.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = Carbon::parse($this->input('starts_at'));
            $end = Carbon::parse($this->input('ends_at'));
            $timezone = $this->room()->office->timezone;

            if (! $this->isQuarterHour($start, $timezone) || ! $this->isQuarterHour($end, $timezone)) {
                $validator->errors()->add(
                    'starts_at',
                    'Начало и конец должны быть кратны 15 минутам по времени офиса: 10:00, 10:15, 10:30 или 10:45.',
                );
            }

            $seconds = $end->getTimestamp() - $start->getTimestamp();

            if ($seconds < self::MIN_DURATION_SECONDS || $seconds > self::MAX_DURATION_SECONDS || $seconds % self::STEP_SECONDS !== 0) {
                $validator->errors()->add(
                    'ends_at',
                    'Длительность должна быть от 15 минут до 4 часов с шагом 15 минут.',
                );
            }

            if (! $this->room()->office->fitsWorkingHours($start, $end)) {
                $validator->errors()->add(
                    'starts_at',
                    'Бронь должна целиком попадать в рабочее время офиса, с 09:00 до 20:00.',
                );
            }
        });
    }

    /**
     * Возвращает проверенное начало как мгновение в UTC.
     *
     * Колонка без пояса, а format() иначе записал бы цифры часов из смещения клиента.
     */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->validated('starts_at'))->utc();
    }

    /**
     * Возвращает проверенное окончание как мгновение в UTC.
     *
     * Колонка без пояса, а format() иначе записал бы цифры часов из смещения клиента.
     */
    public function endsAt(): Carbon
    {
        return Carbon::parse($this->validated('ends_at'))->utc();
    }

    /**
     * Загружает указанную комнату вместе с офисом и кэширует её на время запроса.
     */
    public function room(): Room
    {
        return $this->room ??= Room::query()->with('office')->findOrFail($this->integer('room_id'));
    }

    /**
     * Создаёт правило для строгой проверки ISO 8601 с обязательным смещением.
     */
    private function iso8601WithOffsetRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! $this->isValidIso8601WithOffset($value)) {
                $fail('Укажите время в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.');
            }
        };
    }

    /**
     * Проверяет форму даты, допустимый диапазон смещения и возможность разбора Carbon.
     */
    private function isValidIso8601WithOffset(string $value): bool
    {
        if (preg_match(self::ISO8601_WITH_OFFSET, $value, $matches) !== 1) {
            return false;
        }

        if (($matches[1] ?? null) !== null) {
            $hours = (int) $matches[2];
            $minutes = (int) (($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? '0'));

            if ($hours > 23 || $minutes > 59) {
                return false;
            }
        }

        try {
            Carbon::parse($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Проверяет, что локальное время офиса попадает на границу четверти часа.
     */
    private function isQuarterHour(CarbonInterface $instant, string $timezone): bool
    {
        $local = Carbon::parse($instant)->timezone($timezone);

        return $local->second === 0
            && $local->micro === 0
            && $local->minute % 15 === 0;
    }
}
