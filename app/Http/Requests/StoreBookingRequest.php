<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    private const MIN_DURATION_SECONDS = 15 * 60;

    private const MAX_DURATION_SECONDS = 4 * 60 * 60;

    private const STEP_SECONDS = 15 * 60;

    private ?Room $room = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', 'date'],
            'ends_at' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', 'date', 'after:starts_at'],
        ];
    }

    /**
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
            'starts_at.regex' => 'Укажите начало в формате ISO 8601 со смещением, например 2026-11-02T10:00:00+03:00.',
            'starts_at.date' => 'Время начала указано неверно.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.regex' => 'Укажите окончание в формате ISO 8601 со смещением, например 2026-11-02T11:00:00+03:00.',
            'ends_at.date' => 'Время окончания указано неверно.',
            'ends_at.after' => 'Время окончания должно быть позже начала.',
        ];
    }

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
     * Мгновение в UTC. Колонка без пояса, а format() иначе записал бы цифры часов из смещения клиента.
     */
    public function startsAt(): Carbon
    {
        return Carbon::parse($this->validated('starts_at'))->utc();
    }

    /**
     * Мгновение в UTC. Колонка без пояса, а format() иначе записал бы цифры часов из смещения клиента.
     */
    public function endsAt(): Carbon
    {
        return Carbon::parse($this->validated('ends_at'))->utc();
    }

    public function room(): Room
    {
        return $this->room ??= Room::query()->with('office')->findOrFail($this->integer('room_id'));
    }

    private function isQuarterHour(CarbonInterface $instant, string $timezone): bool
    {
        $local = Carbon::parse($instant)->timezone($timezone);

        return $local->second === 0 && $local->minute % 15 === 0;
    }
}
