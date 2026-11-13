<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Enums\OfferType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\Resturants\Support\RestaurantPromotionCapability;
use Modules\User\Services\RestaurantFoodEligibility;

final class OfferService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    public function store(OfferData $data): Offer
    {
        return DB::transaction(function () use ($data): Offer {
            $restaurantId = $this->assertOwner($data);
            $attributes = $this->attributes($data);
            $this->assertProducts($data, $restaurantId);
            if (empty($data->productIds)) {
                throw ValidationException::withMessages(['productIds' => 'Select at least one eligible product.']);
            }
            $offer = Offer::create($attributes);
            if ($data->productIds !== null) {
                $offer->products()->sync($data->productIds);
            }
            if ($data->offerType === OfferType::BuyXGetY->value) {
                $offer->rewardProducts()->sync($data->rewardProductIds ?? []);
            }
            $this->activityLogService->logOfferCreated($offer, $restaurantId);

            return $offer;
        });
    }

    public function update(OfferData $data, Offer $offer): Offer
    {
        return DB::transaction(function () use ($data, $offer): Offer {
            $restaurantId = $this->assertOwner($data);
            $offer = Offer::query()->whereKey($offer->id)->where('restaurant_id', $restaurantId)->lockForUpdate()->firstOrFail();
            if (! $offer->isProductDiscount() && ! RestaurantPromotionCapability::supported()) {
                throw ValidationException::withMessages(['offerType' => 'This promotion requires a compatible client.']);
            }
            $this->assertProducts($data, $restaurantId);
            $oldAttributes = $offer->getAttributes();
            $offer->fill($this->attributes($data));
            if ($data->productIds === [] || ($data->productIds === null && $offer->is_active && ! $offer->products()
                ->where('products.restaurant_id', $restaurantId)->where('products.catalog_role', 'independent')
                ->where(fn ($q) => $q->where('products.is_available', true)->orWhere(fn ($q) => $q->where('products.is_available', false)->where('products.unavailable_until', '<', now())))->exists())) {
                throw ValidationException::withMessages(['productIds' => 'Select at least one eligible product.']);
            }
            $ruleChanged = $offer->isDirty();
            if ($data->productIds !== null) {
                $changes = $offer->products()->sync($data->productIds);
                $ruleChanged = $ruleChanged || $this->changed($changes);
            }
            if ($data->offerType === OfferType::BuyXGetY->value || ($oldAttributes['offer_type'] ?? 'product_discount') !== 'product_discount') {
                $changes = $offer->rewardProducts()->sync($data->rewardProductIds ?? []);
                $ruleChanged = $ruleChanged || $this->changed($changes);
            }
            if ($ruleChanged) {
                $offer->rule_version = (int) ($offer->rule_version ?? 1) + 1;
            }
            $offer->save();
            $this->activityLogService->logOfferUpdated($offer, $restaurantId, $oldAttributes);

            return $offer;
        });
    }

    private function changed(array $changes): bool
    {
        return $changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== [];
    }

    private function assertOwner(OfferData $data): int
    {
        $id = app(RestaurantOwnerContext::class)->restaurantId();
        abort_unless($data->restaurantId === $id, 403);

        return $id;
    }

    private function attributes(OfferData $data): array
    {
        $type = OfferType::tryFrom($data->offerType);
        if ($type === null) {
            throw ValidationException::withMessages(['offerType' => 'Unsupported promotion type.']);
        }
        $advanced = $type === OfferType::BuyXGetY;
        if ($advanced) {
            if (! RestaurantPromotionCapability::supported()) {
                throw ValidationException::withMessages(['offerType' => 'Advanced restaurant promotions are not enabled for this client.']);
            }
            $mode = OfferRewardType::tryFrom($data->rewardType ?? '');
            if ($mode === null || $data->buyQuantity === null || $data->buyQuantity < 1 || $data->buyQuantity > 100
                || $data->rewardQuantity === null || $data->rewardQuantity < 1 || $data->rewardQuantity > 100
                || $data->rewardDiscountPercent === null || $data->rewardDiscountPercent < 0.01 || $data->rewardDiscountPercent > 100
                || $data->isRepeatable === null || empty($data->productIds)
                || ($data->maxApplicationsPerOrder !== null && ($data->maxApplicationsPerOrder < 1 || $data->maxApplicationsPerOrder > 100))
                || ($mode === OfferRewardType::SpecificProducts && empty($data->rewardProductIds))
                || ($mode !== OfferRewardType::SpecificProducts && ! empty($data->rewardProductIds))
                || array_intersect($data->productIds ?? [], $data->rewardProductIds ?? []) !== []) {
                throw ValidationException::withMessages(['offerType' => 'Invalid buy-and-reward rule.']);
            }
        }

        // Existing clients retain the non-null-only legacy update semantics.
        return [
            ...$data->onlyModelAttributes(),
            'offer_type' => $type->value,
            ...($data->showUpcoming !== null ? ['show_upcoming' => $data->showUpcoming] : []),
            'buy_quantity' => $advanced ? $data->buyQuantity : null,
            'reward_quantity' => $advanced ? $data->rewardQuantity : null,
            'reward_type' => $advanced ? $data->rewardType : null,
            'reward_discount_percent' => $advanced ? $data->rewardDiscountPercent : null,
            'is_repeatable' => $advanced && $data->isRepeatable,
            'max_applications_per_order' => $advanced ? $data->maxApplicationsPerOrder : null,
            ...($advanced ? [
                'discount_type' => 'fixed_amount',
                'discount_value' => 0,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'description' => $data->description,
            ] : []),
        ];
    }

    private function assertProducts(OfferData $data, int $restaurantId): void
    {
        foreach (['productIds' => $data->productIds, 'rewardProductIds' => $data->rewardProductIds] as $field => $ids) {
            if ($ids === null || $ids === []) {
                continue;
            }
            $ids = array_map('intval', $ids);
            $query = Product::query()->where('restaurant_id', $restaurantId)->whereIn('id', $ids);
            RestaurantFoodEligibility::browsableProducts($query);
            if ($query->count() !== count($ids)) {
                throw ValidationException::withMessages([$field => 'Some products do not belong to this restaurant or are not eligible.']);
            }
        }
    }
}
