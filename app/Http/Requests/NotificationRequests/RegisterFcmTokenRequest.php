<?php

declare(strict_types=1);

namespace App\Http\Requests\NotificationRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterFcmTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fcmToken' => ['required', 'string', 'min:16', 'max:4096'],
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
