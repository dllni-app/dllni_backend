<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Http\Requests\Concerns\NormalizesPhoneInput;
use Illuminate\Foundation\Http\FormRequest;

final class ResetPasswordConfirmRequest extends FormRequest
{
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
            'phone' => 'required|string|max:32|exists:users,phone',
            'otp' => 'required|string|min:4|max:12',
            'password' => 'required|string|min:8|max:255|confirmed',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => $this->normalizePhoneInput($this->input('phone')),
        ]);
    }
}
