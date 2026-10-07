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
            // Legacy single-value field is still accepted for already-published clients.
            'preferred_work_type' => ['sometimes', 'string', Rule::in(WorkerPreferredWorkType::values())],
            'preferred_work_types' => ['sometimes', 'array', 'min:1', 'max:3'],
            'preferred_work_types.*' => ['string', 'distinct', Rule::in(WorkerPreferredWorkType::selectableValues())],
            'avatar' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'isActive' => ['nullable', 'boolean'],
            'homeAddress' => ['nullable', 'string', 'max:255'],
            'homeLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'homeLongitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
