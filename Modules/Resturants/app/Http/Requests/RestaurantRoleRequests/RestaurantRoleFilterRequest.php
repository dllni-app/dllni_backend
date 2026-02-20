<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantRoleRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantRoleFilterRequest extends FormRequest
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
            'sort' => 'sometimes|string|in:name,-name,slug,-slug,created_at,-created_at',
        ];
    }
}
