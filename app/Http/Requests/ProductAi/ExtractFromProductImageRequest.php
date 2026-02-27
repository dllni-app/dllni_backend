<?php

declare(strict_types=1);

namespace App\Http\Requests\ProductAi;

use Illuminate\Foundation\Http\FormRequest;

final class ExtractFromProductImageRequest extends FormRequest
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
            'image' => ['required', 'file', 'image', 'max:8192'],
            'locale' => ['sometimes', 'string', 'in:ar,en'],
        ];
    }
}
