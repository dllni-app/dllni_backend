<?php

declare(strict_types=1);

namespace App\Http\Requests\Test;

use Illuminate\Foundation\Http\FormRequest;

final class SmsProviderTestRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:/^9639\d{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'The phone number must be a valid Syrian mobile number, for example 0944000111 or +963944000111.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_array($phone)) {
            $phone = $phone[0] ?? null;
        }

        if (! is_string($phone)) {
            return;
        }

        $phone = preg_replace('/[\s\-()]+/', '', trim($phone)) ?? '';

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }

        if (str_starts_with($phone, '09')) {
            $phone = '963'.substr($phone, 1);
        } elseif (strlen($phone) === 9 && str_starts_with($phone, '9')) {
            $phone = '963'.$phone;
        }

        $this->merge([
            'phone' => $phone,
        ]);
    }
}
