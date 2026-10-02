<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserSmartSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(['restaurant', 'supermarket'])],
            'query' => ['required', 'string', 'min:1', 'max:5000', 'regex:/.*\\S.*/'],
            'locale' => ['sometimes', 'nullable', 'string', Rule::in(['ar', 'en'])],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'topK' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
