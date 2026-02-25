<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'userId' => 'required|exists:users,id',
            'restaurantRoleId' => 'required|exists:restaurant_roles,id',
        ];
    }
}
