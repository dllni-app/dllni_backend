<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Enums\WorkerPreferredWorkType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WorkerAccountProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) auth()->user()?->worker;
    }

    public function rules(): array
    {
        $userId = auth()->id();

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($userId)],
            'bio' => ['nullable', 'string'],
            'birthday' => ['nullable', 'date'],
            'preferred_work_type' => ['sometimes', 'string', Rule::in(WorkerPreferredWorkType::values())],
            'avatar' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'isActive' => ['nullable', 'boolean'],
            'homeAddress' => ['nullable', 'string', 'max:255'],
            'homeLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'homeLongitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $worker = auth()->user()?->worker;
            if (! $worker) {
                return;
            }

            $isActive = array_key_exists('isActive', $this->all())
                ? (bool) $this->input('isActive')
                : (bool) $worker->is_active;

            if (! $isActive) {
                return;
            }

            // Activation depends on the approved home_* values only. Pending
            // location submissions wait for admin approval before becoming active home.
            $homeAddress = $worker->home_address;
            $homeLatitude = $worker->home_latitude;
            $homeLongitude = $worker->home_longitude;

            if ($homeAddress === null || mb_trim((string) $homeAddress) === '') {
                $validator->errors()->add('homeAddress', 'Active workers must have a home address.');
            }

            if ($homeLatitude === null) {
                $validator->errors()->add('homeLatitude', 'Active workers must have home latitude.');
            }

            if ($homeLongitude === null) {
                $validator->errors()->add('homeLongitude', 'Active workers must have home longitude.');
            }
        });
    }
}
