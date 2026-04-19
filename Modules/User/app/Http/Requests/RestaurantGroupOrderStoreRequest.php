<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RestaurantGroupOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => ['required', 'integer', 'exists:restaurants,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'durationMinutes' => ['required', 'integer', Rule::in([15, 30, 45, 60, 90, 120])],
        ];
    }
}
