<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Resturants\Models\Restaurant;

final class OfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'name' => 'required|string|max:255',
            'discountType' => 'required|string|in:percentage,fixed_amount',
            'discountValue' => 'required|numeric|min:0',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('restaurantId')) {
            return;
        }

        $isRestaurantOwnerPath = str_contains($this->path(), 'api/v1/restaurant-owner/')
            || str_contains($this->path(), 'api/v1/resturant-owner/');

        if (! $isRestaurantOwnerPath) {
            return;
        }

        $restaurantId = Restaurant::query()
            ->where('user_id', auth()->id())
            ->value('id');

        if ($restaurantId !== null) {
            $this->merge([
                'restaurantId' => (int) $restaurantId,
            ]);
        }
    }
}
