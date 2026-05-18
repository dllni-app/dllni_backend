<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            'email' => 'required|string|email',
            'password' => 'required|string',
            'fcmToken' => ['nullable', 'string', 'min:16', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'fcmToken',
            'fcm_token',
            'deviceToken',
            'device_token',
            'pushToken',
            'push_token',
            'token',
        ];

        $token = null;
        foreach ($aliases as $key) {
            if (! $this->exists($key)) {
                continue;
            }

            $token = $this->input($key);
            break;
        }

        if (is_string($token)) {
            $token = trim($token);
        }

        $this->merge([
            'fcmToken' => $token,
        ]);
    }
}
