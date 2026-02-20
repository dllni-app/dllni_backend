<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $promoCodeId = $this->route('promo_code')?->id;

        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'code' => 'required|string|max:255|unique:promo_codes,code,'.$promoCodeId,
            'discountType' => 'required|string|in:percentage,fixed_amount',
            'discountValue' => 'required|numeric|min:0',
            'minOrderAmount' => 'nullable|numeric|min:0',
            'usageLimit' => 'nullable|integer|min:0',
            'usageCount' => 'nullable|integer|min:0',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'nullable|boolean',
        ];
    }
}
