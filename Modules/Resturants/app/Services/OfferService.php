<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Models\Offer;

final class OfferService
{
    public function store(OfferData $data): Offer
    {
        return DB::transaction(static function () use ($data) {
            return Offer::create($data->onlyModelAttributes());
        });
    }

    public function update(OfferData $data, Offer $offer): Offer
    {
        return DB::transaction(static function () use ($data, $offer) {
            tap($offer)->update($data->onlyModelAttributes());

            return $offer;
        });
    }
}
