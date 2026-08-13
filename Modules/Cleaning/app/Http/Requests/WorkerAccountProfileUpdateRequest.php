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
}
