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
            'phone' => ['nullable', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($userId)],
            'avatar' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:2048'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
