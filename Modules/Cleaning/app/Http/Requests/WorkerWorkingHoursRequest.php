<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Enums\DayOfWeek;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class WorkerWorkingHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) auth()->user()?->worker;
    }

    public function rules(): array
    {
        $dayValues = DayOfWeek::values();
        $rules = [
            'defaultWorkingHours' => ['required', 'array'],
        ];
        foreach ($dayValues as $day) {
            $rules["defaultWorkingHours.{$day}"] = ['required', 'array'];
            $rules["defaultWorkingHours.{$day}.available"] = ['required', 'boolean'];
            $rules["defaultWorkingHours.{$day}.data"] = ['nullable', 'array'];
            $rules["defaultWorkingHours.{$day}.data.*"] = [
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value) || count($value) !== 1) {
                        $fail(__('Each period must be a single object with one time range, e.g. {"10:00": "16:00"}.'));

                        return;
                    }
                    $keys = array_keys($value);
                    $start = $keys[0];
                    $end = $value[$start];
                    if (! is_string($start) || ! is_string($end)) {
                        $fail(__('Each period must have start and end time strings, e.g. {"10:00": "16:00"}.'));

                        return;
                    }
                    if (! $this->isTimeString($start) || ! $this->isTimeString($end)) {
                        $fail(__('Times must be in HH:MM format (e.g. 09:00, 23:00).'));
                    }
                },
            ];
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $hours = $this->input('defaultWorkingHours');
            if (! is_array($hours)) {
                return;
            }
            $allowed = array_flip(DayOfWeek::values());
            foreach (array_keys($hours) as $key) {
                if (! isset($allowed[$key])) {
                    $validator->errors()->add(
                        'defaultWorkingHours',
                        __('Day must be one of: :days.', ['days' => implode(', ', DayOfWeek::values())])
                    );
                    break;
                }
            }
        });
    }

    private function isTimeString(string $value): bool
    {
        return (bool) preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $value);
    }
}
