<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserRestaurantCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => ['required', 'integer', 'exists:restaurants,id'],
            'orderType' => ['required', 'string', Rule::in(['delivery', 'pickup', 'dine_in'])],
            'promoCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'specialInstructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
