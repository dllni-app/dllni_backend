<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $offer = $this->route('sm_offer');
            if ($offer instanceof SmOffer && $offer->promotion_type === 'buy_x_get_y'
                && (! $this->has('promotionType') || ! \Modules\Supermarket\Support\SupermarketPromotionCapability::supported())) {
                $validator->errors()->add('promotionType', 'يلزم تطبيق متوافق وقاعدة عرض صريحة لتعديل هذا العرض.');
            }
            if (! $this->advanced()) {
                return;
            }
            if (! \Modules\Supermarket\Support\SupermarketPromotionCapability::supported()) {
                $validator->errors()->add('promotionType', 'العروض المتقدمة غير مفعلة لهذا التطبيق.');
            }
            if (array_intersect(array_column((array) $this->input('offerProducts', []), 'productId'), (array) $this->input('rewardProductIds', [])) !== []) {
                $validator->errors()->add('rewardProductIds', 'يجب أن تختلف منتجات المكافأة عن منتجات الشراء.');
            }
        });
    }

    public function rules(): array
    {
        /** @var SmOffer|null $offer */
        $offer = $this->route('sm_offer');
        $storeId = $this->integer('storeId') ?: $offer?->store_id;

        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'promotionType' => ['sometimes', 'required', Rule::in(['product_discount', 'buy_x_get_y'])],
            'offerType' => [$this->input('promotionType') === 'buy_x_get_y' ? 'prohibited' : 'sometimes', 'string', 'max:255'],
            'buyQuantity' => [$this->advanced() ? 'required' : 'prohibited', 'integer', 'min:1', 'max:100'],
            'rewardQuantity' => [$this->advanced() ? 'required' : 'prohibited', 'integer', 'min:1', 'max:100'],
            'rewardType' => [$this->advanced() ? 'required' : 'prohibited', Rule::in(['same_product', 'cheapest_eligible', 'specific_products'])],
            'rewardDiscountPercent' => [$this->advanced() ? 'required' : 'prohibited', 'numeric', 'min:0.01', 'max:100', 'decimal:0,2'],
            'isRepeatable' => [$this->advanced() ? 'required' : 'prohibited', 'boolean'],
            'maxApplicationsPerOrder' => [$this->advanced() ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:100'],
            'rewardProductIds' => [$this->advanced() && $this->input('rewardType') === 'specific_products' ? 'required' : 'prohibited', 'array', 'min:1', 'max:100'],
            'rewardProductIds.*' => ['integer', 'distinct', Rule::exists('sm_products', 'id')->where('store_id', $storeId)->where('is_available', true)],
            'showUpcoming' => ['sometimes', 'boolean'],
            'discountValue' => [$this->advanced() ? 'prohibited' : 'nullable', 'numeric', 'min:0'],
            'discountPercent' => [$this->advanced() ? 'prohibited' : 'nullable', 'integer', 'min:0', 'max:100'],
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'sometimes|boolean',
            'offerProducts' => [$this->advanced() ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'offerProducts.*.productId' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('sm_products', 'id')->where(static function ($query) use ($storeId): void {
                    if ($storeId !== null) {
                        $query->where('store_id', $storeId);
                    }
                }),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['startsAt', 'endsAt'] as $field) {
            $raw = $this->input($field);
            if (! is_string($raw) || strtotime($raw) === false) {
                continue;
            }
            $date = \Illuminate\Support\Carbon::parse($raw, config('app.timezone'))->setTimezone(config('app.timezone'));
            if ($field === 'endsAt' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
                $date->endOfDay();
            }
            $this->merge([$field => $date->toDateTimeString()]);
        }
    }

    private function advanced(): bool
    {
        return $this->input('promotionType') === 'buy_x_get_y';
    }
}
