<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $couponId = $this->route('sm_coupon');

        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sm_coupons', 'code')->ignore($couponId),
            ],
            'type' => 'sometimes|required|string|max:255',
            'value' => 'nullable|numeric|min:0',
            'percent' => 'nullable|integer|min:0|max:100',
            'minOrderAmount' => 'nullable|numeric|min:0',
            'maxDiscountAmount' => 'nullable|numeric|min:0',
            'usageLimit' => 'nullable|integer|min:0',
            'usedCount' => 'sometimes|integer|min:0',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
