<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Http\Requests\Concerns\ResolvesFcmToken;
use App\Http\Requests\Concerns\NormalizesPhoneInput;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    use ResolvesFcmToken;
    use NormalizesPhoneInput;

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
            'phone' => 'required|string|max:32',
            'password' => 'required|string|max:255',
            'fcmToken' => ['nullable', 'string', 'min:16', 'max:4096'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->normalizePhoneInput($this->input('phone')),
            'fcmToken' => $this->resolveFcmToken(),
        ]);
    }
}
