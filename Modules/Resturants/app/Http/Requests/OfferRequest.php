<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Enums\OfferType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Illuminate\Support\Carbon;

final class OfferRequest extends FormRequest
{
    private bool $explicitType = false;

    public function authorize(): bool
    {
        $restaurantId = app(RestaurantOwnerContext::class)->restaurantId();
        $offer = $this->route('offer');

        abort_if($offer instanceof Offer && (int) $offer->restaurant_id !== $restaurantId, 404);

        return true;
    }

    public function rules(): array
    {
        $advanced = $this->input('offerType') === OfferType::BuyXGetY->value;
        $specific = $advanced && $this->input('rewardType') === OfferRewardType::SpecificProducts->value;
        $advancedProduct = Rule::exists('products', 'id')->where(
            fn ($query) => $query->where('restaurant_id', $this->input('restaurantId'))
                ->where('catalog_role', 'independent')
                ->where(fn ($q) => $q->where('is_available', true)
                    ->orWhere(fn ($q) => $q->where('is_available', false)
                        ->whereNotNull('unavailable_until')->where('unavailable_until', '<', now())))
        );

        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'offerType' => ['required', Rule::enum(OfferType::class)],
            'discountType' => [$advanced ? 'prohibited' : 'required', 'nullable', 'string', Rule::in(['percentage', 'fixed_amount'])],
            'discountValue' => [
                $advanced ? 'prohibited' : 'required', 'nullable', 'numeric', 'min:0',
                Rule::when($this->input('discountType') === 'percentage', ['max:100']),
            ],
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'nullable|boolean',
            'showUpcoming' => 'sometimes|boolean',
            'productIds' => [($advanced || $this->isMethod('post')) ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'productIds.*' => ['integer', 'distinct', $advancedProduct],
            'buyQuantity' => [$advanced ? 'required' : 'prohibited', 'nullable', 'integer', 'min:1', 'max:100'],
            'rewardQuantity' => [$advanced ? 'required' : 'prohibited', 'nullable', 'integer', 'min:1', 'max:100'],
            'rewardType' => [$advanced ? 'required' : 'prohibited', 'nullable', Rule::enum(OfferRewardType::class)],
            'rewardDiscountPercent' => [$advanced ? 'required' : 'prohibited', 'nullable', 'numeric', 'min:0.01', 'max:100', 'decimal:0,2'],
            'isRepeatable' => [$advanced ? 'required' : 'prohibited', 'nullable', 'boolean'],
            'maxApplicationsPerOrder' => [$advanced ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:100'],
            'rewardProductIds' => [$specific ? 'required' : 'prohibited', 'nullable', 'array', ...($specific ? ['min:1', 'max:100'] : [])],
            'rewardProductIds.*' => ['integer', 'distinct', $advancedProduct],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $offer = $this->route('offer');
            if ($offer instanceof Offer && $offer->offer_type === OfferType::BuyXGetY && ! $this->explicitType) {
                $validator->errors()->add('offerType', 'An explicit promotion rule is required to edit this offer.');
            }
            if ($this->input('offerType') !== OfferType::BuyXGetY->value) {
                return;
            }
            if (! RestaurantPromotionCapability::supported()) {
                $validator->errors()->add('offerType', 'Advanced restaurant promotions are not enabled.');
            }
            $products = $this->input('productIds');
            $rewards = $this->input('rewardProductIds');
            if (is_array($products) && is_array($rewards) && array_intersect($products, $rewards) !== []) {
                $validator->errors()->add('rewardProductIds', 'Qualifying and specific reward products must be different.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $startsAt = $this->input('startsAt', $this->input('starts_at'));
        $endsAt = $this->input('endsAt', $this->input('ends_at'));

        if ($this->isMethod('post') && blank($startsAt)) {
            $startsAt = now()->toDateTimeString();
        }

        $this->explicitType = $this->has('offerType') || $this->has('offer_type');
        $timezone = config('app.timezone');
        // Normalize explicit offsets and date-only boundaries, but leave invalid
        // input untouched for Laravel's date validation.
        if (is_string($startsAt) && strtotime($startsAt) !== false) {
            $startsAt = Carbon::parse($startsAt, $timezone)->setTimezone($timezone)->toDateTimeString();
        }
        if (is_string($endsAt) && strtotime($endsAt) !== false) {
            $end = Carbon::parse($endsAt, $timezone)->setTimezone($timezone);
            $endsAt = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $endsAt) ? $end->endOfDay()->toDateTimeString() : $end->toDateTimeString();
        }

        $this->merge([
            'restaurantId' => app(RestaurantOwnerContext::class)->restaurantId(),
            'offerType' => $this->input('offerType', $this->input('offer_type', OfferType::ProductDiscount->value)),
            'discountType' => $this->input('discountType', $this->input('discount_type')),
            'discountValue' => $this->input('discountValue', $this->input('discount_value')),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'isActive' => $this->input('isActive', $this->input('is_active')),
            ...($this->has('showUpcoming') || $this->has('show_upcoming') ? ['showUpcoming' => $this->input('showUpcoming', $this->input('show_upcoming'))] : []),
            ...($this->has('productIds') || $this->has('product_ids') ? ['productIds' => $this->input('productIds', $this->input('product_ids'))] : []),
        ]);
    }
}