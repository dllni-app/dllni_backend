<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantCheckoutRequest extends FormRequest
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
            'restaurantId' => ['required', 'integer', 'exists:restaurants,id'],
            'orderType' => ['required', 'string', 'in:delivery,pickup'],
            'pickupMode' => ['sometimes', 'string', 'in:immediate_pickup,scheduled_pickup'],
            'pickupScheduledFor' => ['sometimes', 'nullable', 'date'],
            'promoCode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'specialInstructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
