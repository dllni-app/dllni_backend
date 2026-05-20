<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ResolvesFcmToken;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            'email' => 'required|string|email',
            'password' => 'required|string',
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
