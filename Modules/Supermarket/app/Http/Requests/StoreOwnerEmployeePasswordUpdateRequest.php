<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOwnerEmployeePasswordUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'newPassword' => ['required', 'string', 'min:8', 'max:255'],
            'newPasswordConfirmation' => ['required', 'string', 'same:newPassword'],
        ];
    }
}
