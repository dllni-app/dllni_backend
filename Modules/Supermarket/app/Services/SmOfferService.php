<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOfferData;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    public function store(SmOfferData $data, ?array $offerProducts = null, ?array $rewardProductIds = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offerProducts, $rewardProductIds) {
            $this->assertAdvanced($data, $offerProducts, $rewardProductIds);
            $attributes = $data->onlyModelAttributes();
            if ($data->promotionType === 'buy_x_get_y') {
                $attributes = [...$attributes, 'offer_type' => 'value', 'discount_value' => 0, 'discount_percent' => null];
            }
            $offer = SmOffer::create($attributes);
            if ($rewardProductIds !== null) {
                $offer->rewardProducts()->sync($rewardProductIds);
            }
            $this->syncOfferProducts($offer, $offerProducts);
            $this->activityLogService->logSmOfferCreated($offer, (int) $offer->store_id);

            return $offer;
        });
    }

    public function update(SmOfferData $data, SmOffer $offer, ?array $offerProducts = null, ?array $rewardProductIds = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offer, $offerProducts, $rewardProductIds) {
            $offer = SmOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ($offer->promotion_type === 'buy_x_get_y' && ($data->promotionType === null || ! \Modules\Supermarket\Support\SupermarketPromotionCapability::supported())) {
                throw \Illuminate\Validation\ValidationException::withMessages(['promotionType' => 'يلزم تطبيق متوافق لتعديل العرض.']);
            }
            $this->assertAdvanced($data, $offerProducts, $rewardProductIds);
            $oldAttributes = $offer->getAttributes();
            $attributes = $data->onlyModelAttributes();
            if ($data->promotionType === 'buy_x_get_y') {
                $attributes = [...$attributes, 'offer_type' => 'value', 'discount_value' => 0, 'discount_percent' => null, 'max_applications_per_order' => $data->maxApplicationsPerOrder, 'starts_at' => $data->startsAt, 'ends_at' => $data->endsAt];
            }
            if ($data->promotionType === 'product_discount') {
                foreach (['buy_quantity', 'reward_quantity', 'reward_type', 'reward_discount_percent', 'max_applications_per_order'] as $key) {
                    $attributes[$key] = null;
                }
                $attributes['is_repeatable'] = false;
                $rewardProductIds = [];
            }
            $offer->fill($attributes);
            $offer->rule_version = (int) $offer->rule_version + 1;
            $offer->save();
            if ($rewardProductIds !== null) {
                $offer->rewardProducts()->sync($rewardProductIds);
            }
            $this->syncOfferProducts($offer, $offerProducts);
            $this->activityLogService->logSmOfferUpdated($offer, (int) $offer->store_id, $oldAttributes);

            return $offer;
        });
    }

    private function assertAdvanced(SmOfferData $data, ?array $products, ?array $rewards): void
    {
        if ($data->promotionType !== 'buy_x_get_y') {
            return;
        }
        $ids = array_column($products ?? [], 'productId');
        if (! \Modules\Supermarket\Support\SupermarketPromotionCapability::supported() || $ids === []
            || ! in_array($data->rewardType, ['same_product', 'cheapest_eligible', 'specific_products'], true)
            || ($data->buyQuantity ?? 0) < 1 || $data->buyQuantity > 100 || ($data->rewardQuantity ?? 0) < 1 || $data->rewardQuantity > 100
            || ($data->rewardDiscountPercent ?? 0) < 0.01 || $data->rewardDiscountPercent > 100 || $data->isRepeatable === null
            || ($data->maxApplicationsPerOrder !== null && ($data->maxApplicationsPerOrder < 1 || $data->maxApplicationsPerOrder > 100))
            || ($data->rewardType === 'specific_products' && empty($rewards))
            || ($data->rewardType !== 'specific_products' && ! empty($rewards)) || array_intersect($ids, $rewards ?? []) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['promotionType' => 'قاعدة الشراء والمكافأة غير صالحة.']);
        }
        foreach (array_unique([...$ids, ...($rewards ?? [])]) as $id) {
            if (! \Modules\Supermarket\Models\SmProduct::query()->whereKey($id)->where('store_id', $data->storeId)->where('is_available', true)
                ->where(fn ($q) => $q->whereNull('stock_quantity')->orWhere('stock_quantity', '>', 0))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['offerProducts' => 'المنتجات يجب أن تكون متاحة وتابعة للمتجر نفسه.']);
            }
        }
    }

    private function syncOfferProducts(SmOffer $offer, ?array $offerProducts): void
    {
        if ($offerProducts === null) {
            return;
        }

        $offer->offerProducts()->delete();

        if ($offerProducts === []) {
            return;
        }

        $offer->offerProducts()->createMany(array_map(static function (array $offerProduct): array {
            return [
                'product_id' => $offerProduct['productId'],
            ];
        }, $offerProducts));
    }
}
