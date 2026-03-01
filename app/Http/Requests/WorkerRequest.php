<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DayOfWeek;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class WorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $dayValues = DayOfWeek::values();

        $rules = [
            'userId' => 'required|exists:users,id',
            'firstName' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'averageRating' => 'nullable|numeric',
            'totalCompletedJobs' => 'nullable|integer|min:0',
            'trustScore' => 'nullable|integer|min:0|max:100',
            'acceptanceRate' => 'nullable|numeric',
            'cancellationRate' => 'nullable|numeric',
            'openDisputesCount' => 'nullable|integer|min:0',
            'isActive' => 'nullable|boolean',
            'isSuspended' => 'nullable|boolean',
            'suspendedUntil' => 'nullable|date',
            'homeAddress' => 'nullable|string|max:255',
            'homeLatitude' => 'nullable|numeric',
            'homeLongitude' => 'nullable|numeric',
            'defaultWorkingHours' => ['nullable', 'array'],
            'avatar' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
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
                    foreach ($value as $index => $period) {
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
            if (is_array($hours)) {
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
            }
        });
    }
}
