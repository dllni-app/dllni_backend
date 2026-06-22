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
            'code' => 'required|string|max:255|unique:promo_codes,code,'.$promoCodeId,
            'discountType' => 'required|string|in:percentage,fixed_amount',
            'discountValue' => 'required|numeric|min:0',
            'minOrderAmount' => 'nullable|numeric|min:0',
            'usageLimit' => 'nullable|integer|min:0',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'nullable|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $isCreate = $this->isMethod('post');
        $startsAt = $this->input('startsAt', $this->input('starts_at'));
        $endsAt = $this->input('endsAt', $this->input('ends_at'));

        if ($isCreate && blank($startsAt)) {
            $startsAt = now()->toDateString();
        }

        if ($isCreate && blank($endsAt)) {
            $endsAt = now()->addYear()->toDateString();
        }

        $this->merge([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : $this->input('code'),
            'discountType' => $this->input('discountType', $this->input('discount_type')),
            'discountValue' => $this->input('discountValue', $this->input('discount_value')),
            'minOrderAmount' => $this->input('minOrderAmount', $this->input('min_order_amount')),
            'usageLimit' => $this->input('usageLimit', $this->input('usage_limit')),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'isActive' => $this->input('isActive', $this->input('is_active')),
        ]);
    }
}
