<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\UserModuleType;
use App\Http\Requests\Concerns\ResolvesFcmToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserLoginRequest extends FormRequest
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
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
            // 'moduleType' => ['required', 'string', Rule::enum(UserModuleType::class)],
            'fcmToken' => ['nullable', 'string', 'min:16', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'رقم الهاتف مطلوب.',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً.',

            'password.required' => 'كلمة المرور مطلوبة.',
            'password.string' => 'كلمة المرور يجب أن تكون نصاً.',

            // 'moduleType.required' => 'نوع التطبيق مطلوب.',
            // 'moduleType.string' => 'نوع التطبيق يجب أن يكون نصاً.',
            // 'moduleType.enum' => 'نوع التطبيق غير صالح.',

            'fcmToken.string' => 'رمز الإشعارات يجب أن يكون نصاً.',
            'fcmToken.min' => 'رمز الإشعارات غير صالح.',
            'fcmToken.max' => 'رمز الإشعارات طويل جداً.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'moduleType' => 'نوع التطبيق',
            'fcmToken' => 'رمز الإشعارات',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'moduleType' => $this->input('moduleType', $this->input('module_type')),
            'fcmToken' => $this->resolveFcmToken(),
        ]);
    }
}
