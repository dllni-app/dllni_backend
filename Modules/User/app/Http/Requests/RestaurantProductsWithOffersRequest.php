<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantProductsWithOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurant_id' => 'nullable|integer|exists:restaurants,id',
            'per_page' => 'nullable|integer|between:1,100',
        ];
    }

    public function getPerPage(): int
    {
        return $this->input('per_page', 15);
    }

    public function getRestaurantId(): ?int
    {
        return $this->input('restaurant_id');
    }
}
