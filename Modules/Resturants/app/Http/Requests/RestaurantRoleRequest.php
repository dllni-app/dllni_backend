<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->route('restaurant_role');
        $restaurantId = $role?->restaurant_id ?? $this->input('restaurantId');

        $slugRule = 'required|string|max:255';
        if ($role && $restaurantId) {
            $slugRule .= '|unique:restaurant_roles,slug,'.$role->id.',id,restaurant_id,'.$restaurantId;
        } elseif ($restaurantId) {
            $slugRule .= '|unique:restaurant_roles,slug,NULL,id,restaurant_id,'.$restaurantId;
        }

        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'name' => 'required|string|max:255',
            'slug' => $slugRule,
        ];
    }
}
