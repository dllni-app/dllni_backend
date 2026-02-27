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
            $rules["defaultWorkingHours.{$day}"] = [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === false) {
                        return;
                    }
                    if (! is_array($value)) {
                        $fail(__('validation.array', ['attribute' => $attribute]));

                        return;
                    }
                    foreach ($value as $period) {
                        if (! is_array($period) || ! isset($period['from'], $period['to'])) {
                            $fail(__('Each period must have "from" and "to" time strings.'));

                            return;
                        }
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
}
