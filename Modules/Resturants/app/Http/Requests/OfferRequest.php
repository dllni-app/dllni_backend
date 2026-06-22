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
        $startsAt = $this->input('startsAt', $this->input('starts_at'));
        $endsAt = $this->input('endsAt', $this->input('ends_at'));

        if ($this->isMethod('post') && blank($startsAt)) {
            $startsAt = now()->toDateString();
        }

        $this->merge([
            'discountType' => $this->input('discountType', $this->input('discount_type')),
            'discountValue' => $this->input('discountValue', $this->input('discount_value')),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'isActive' => $this->input('isActive', $this->input('is_active')),
        ]);

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
