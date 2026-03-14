<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DashboardOverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isRestaurantPrefix = str_contains($this->path(), 'api/v1/restaurant/') && ! str_contains($this->path(), 'restaurant-owner');

        return [
            'restaurantId' => $isRestaurantPrefix
                ? ['required', 'exists:restaurants,id']
                : ['prohibited'],
        ];
    }
}
