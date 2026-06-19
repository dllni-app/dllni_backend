<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Http\Requests\Concerns\NormalizesPhoneInput;
use App\Http\Requests\Concerns\ResolvesFcmToken;
use Illuminate\Foundation\Http\FormRequest;

final class LoginVerifyRequest extends FormRequest
{
    use NormalizesPhoneInput;
    use ResolvesFcmToken;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => 'required|string|max:32',
            'password' => 'required|string|max:255',
            'otp' => 'required|string|min:4|max:12',
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
