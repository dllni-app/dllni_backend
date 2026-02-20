<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantStaffRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantStaffFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.userId' => 'sometimes|exists:users,id',
            'filter.restaurantRoleId' => 'sometimes|exists:restaurant_roles,id',
            'sort' => 'sometimes|string|in:created_at,-created_at',
        ];
    }
}
