<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserNormalizeProductTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:5000', 'regex:/.*\\S.*/'],
            'module' => ['required', 'string', Rule::in(['restaurant', 'supermarket'])],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['ar', 'en'])],
        ];
    }
}
