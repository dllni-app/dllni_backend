<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductAi;

use Illuminate\Foundation\Http\FormRequest;

final class GenerateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
