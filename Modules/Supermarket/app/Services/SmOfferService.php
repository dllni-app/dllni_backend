<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOfferData;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferService
{
    public function store(SmOfferData $data): SmOffer
    {
        return DB::transaction(static function () use ($data) {
            $offer = SmOffer::create($data->onlyModelAttributes());

            return $offer;
        });
    }

    public function update(SmOfferData $data, SmOffer $offer): SmOffer
    {
        return DB::transaction(static function () use ($data, $offer) {
            tap($offer)->update($data->onlyModelAttributes());

            return $offer;
        });
    }
}
