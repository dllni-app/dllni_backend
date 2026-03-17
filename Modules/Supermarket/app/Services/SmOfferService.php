<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOfferData;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferService
{
    public function store(SmOfferData $data, ?array $offerProducts = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offerProducts) {
            $offer = SmOffer::create($data->onlyModelAttributes());
            $this->syncOfferProducts($offer, $offerProducts);

            return $offer;
        });
    }

    public function update(SmOfferData $data, SmOffer $offer, ?array $offerProducts = null): SmOffer
    {
        return DB::transaction(function () use ($data, $offer, $offerProducts) {
            tap($offer)->update($data->onlyModelAttributes());
            $this->syncOfferProducts($offer, $offerProducts);

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
                'offer_price' => $offerProduct['offerPrice'] ?? null,
                'max_quantity' => $offerProduct['maxQuantity'] ?? null,
            ];
        }, $offerProducts));
    }
}
