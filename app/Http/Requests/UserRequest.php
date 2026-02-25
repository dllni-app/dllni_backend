<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'string|max:255',
            'email' => 'string|max:255|unique:users,email',
            'emailVerifiedAt' => 'date',
            'password' => 'string|max:255',
            'rememberToken' => 'string|max:100',
            'primaryImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'images' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048|array',
        ];
    }
}

