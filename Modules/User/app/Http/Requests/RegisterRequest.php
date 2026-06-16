<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:32|unique:users,phone',
            'password' => 'required|string|min:8|max:255',
        ];
    }
}
