<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantV2ReorderPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.orderItemId' => ['required_with:lines', 'integer', 'min:1', 'distinct'],
            'lines.*.quantity' => ['sometimes', 'integer', 'between:1,50'],
            'lines.*.modifierIds' => ['sometimes', 'array', 'max:100'],
            'lines.*.modifierIds.*' => ['integer', 'min:1', 'distinct'],
            'lines.*.substituteProductId' => ['nullable', 'integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
