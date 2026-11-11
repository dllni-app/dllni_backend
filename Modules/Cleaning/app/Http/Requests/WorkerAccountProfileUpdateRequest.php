<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

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
            'preferred_work_types' => ['sometimes', 'required', 'array', 'min:1', 'max:3'],
            'preferred_work_types.*' => ['required', 'string', 'distinct', Rule::in(['cleaning', 'events', 'hourly'])],
            'avatar' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
