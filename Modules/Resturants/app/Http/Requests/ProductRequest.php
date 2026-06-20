<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'prohibited',
            'categoryId' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'discountedPrice' => 'prohibited',
            'discountType' => 'nullable|string|in:percent,percentage,fixed,fixed_amount',
            'discountValue' => 'nullable|numeric|min:0',
            'isAvailable' => 'nullable|boolean',
            'stockQuantity' => 'nullable|integer|min:0',
            'lowStockThreshold' => 'nullable|integer|min:0',
            'preparationTime' => 'nullable|integer|min:0',
            'isFeatured' => 'nullable|boolean',
            'primaryImage' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
            'images' => 'nullable|array',
            'images.*' => 'file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $discountType = $this->input('discountType');
            $discountValue = $this->input('discountValue');

            if (($discountType === null || $discountType === '') && ($discountValue === null || $discountValue === '')) {
                return;
            }

            if ($discountType === null || $discountType === '') {
                $validator->errors()->add('discountType', 'حدد نوع الحسم قبل إدخال قيمة الحسم.');

                return;
            }

            if ($discountValue === null || $discountValue === '') {
                $validator->errors()->add('discountValue', 'أدخل قيمة الحسم قبل تحديد نوع الحسم.');

                return;
            }

            if ($validator->errors()->has('price') || $validator->errors()->has('discountType') || $validator->errors()->has('discountValue')) {
                return;
            }

            $price = (float) $this->input('price');
            $value = (float) $discountValue;
            $normalizedType = match ((string) $discountType) {
                'percent', 'percentage' => 'percentage',
                'fixed', 'fixed_amount' => 'fixed_amount',
                default => null,
            };

            if ($value <= 0) {
                $validator->errors()->add('discountValue', 'قيمة الحسم يجب أن تكون أكبر من صفر.');

                return;
            }

            if ($normalizedType === 'percentage' && $value >= 100) {
                $validator->errors()->add('discountValue', 'نسبة الحسم يجب أن تكون أقل من 100٪.');

                return;
            }

            if ($normalizedType === 'fixed_amount' && $value >= $price) {
                $validator->errors()->add('discountValue', 'قيمة الحسم يجب أن تكون أقل من السعر الأساسي.');
            }
        });
    }
}
