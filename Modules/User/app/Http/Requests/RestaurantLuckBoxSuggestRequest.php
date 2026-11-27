<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantLuckBoxSuggestRequest extends FormRequest
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
            'groupSize' => ['required', 'integer', 'min:1', 'max:50'],
            'budgetPerPerson' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'restrictions' => ['sometimes', 'array', 'max:20'],
            'restrictions.*' => ['string', 'in:vegetarian,gluten_free,nut_free,dairy_free,halal_friendly'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'cuisineTypeId' => ['sometimes', 'nullable', 'integer', 'exists:cuisine_types,id'],
            'restaurantId' => ['sometimes', 'nullable', 'integer', 'exists:restaurants,id'],
            'foodCategoryId' => ['sometimes', 'nullable', 'integer', 'exists:food_discovery_categories,id'],
            'itemTypes' => ['sometimes', 'array', 'max:13'],
            'itemTypes.*' => ['string', 'distinct', \Illuminate\Validation\Rule::in(\Modules\Resturants\Services\ProductClassificationRules::TYPES)],
            'mode' => ['sometimes', 'string', 'in:any,sandwiches,meals,family'],
        ];
    }
}
