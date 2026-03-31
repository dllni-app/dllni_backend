<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantGroupVoteStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'durationMinutes' => ['required', 'integer', 'in:15,30,45,60,90,120'],
            'foodCategoryHint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cuisineTypeId' => ['sometimes', 'nullable', 'integer', 'exists:cuisine_types,id'],
            'options' => ['required', 'array', 'min:2', 'max:10'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'options.*.productId' => ['sometimes', 'nullable', 'integer', 'exists:products,id'],
        ];
    }
}
