<?php

declare(strict_types=1);

namespace App\Http\Requests\NotificationRequests;

use App\Http\Requests\Concerns\ResolvesFcmToken;
use Illuminate\Foundation\Http\FormRequest;

final class RegisterFcmTokenRequest extends FormRequest
{
    use ResolvesFcmToken;

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
        $this->merge([
            'fcmToken' => $this->resolveFcmToken(),
        ]);
    }
}
