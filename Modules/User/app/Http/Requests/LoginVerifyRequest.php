<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Http\Requests\Concerns\ResolvesFcmToken;
use Illuminate\Foundation\Http\FormRequest;

final class LoginVerifyRequest extends FormRequest
{
    use ResolvesFcmToken;

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
            'phone' => 'required|string|max:32|exists:users,phone',
            'password' => 'required|string|max:255',
            'otp' => 'required|string|min:4|max:12',
            'fcmToken' => ['nullable', 'string', 'min:16', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'fcmToken' => $this->resolveFcmToken(),
        ]);
    }
}
