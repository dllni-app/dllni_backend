<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:accepted,preparing,ready_for_pickup,picked_up,completed,cancelled',
            'reason' => 'nullable|string|max:255',
            'customerMessage' => 'nullable|string|max:500',
            'preparationTimeMinutes' => 'sometimes|nullable|integer|min:1|max:120',
        ];
    }
}
