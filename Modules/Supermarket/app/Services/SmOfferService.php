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

    public function store(SmOfferData $data, ?array $offerProducts = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offerProducts) {
            $offer = SmOffer::create($data->onlyModelAttributes());
            $this->syncOfferProducts($offer, $offerProducts);
            $this->activityLogService->logSmOfferCreated($offer, (int) $offer->store_id);

            return $offer;
        });
    }

    public function update(SmOfferData $data, SmOffer $offer, ?array $offerProducts = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offer, $offerProducts) {
            $oldAttributes = $offer->getAttributes();
            tap($offer)->update($data->onlyModelAttributes());
            $this->syncOfferProducts($offer, $offerProducts);
            $this->activityLogService->logSmOfferUpdated($offer, (int) $offer->store_id, $oldAttributes);

            return $offer;
        });
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
