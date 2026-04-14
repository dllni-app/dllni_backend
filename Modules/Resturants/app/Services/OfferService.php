<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\OfferData;
use Modules\Resturants\Models\Offer;

final class OfferService
{
    public function __construct(private ActivityLogService $activityLogService) {}

    public function store(OfferData $data): Offer
    {
        return DB::transaction(function () use ($data) {
            $offer = Offer::create($data->onlyModelAttributes());
            $this->activityLogService->logOfferCreated($offer, (int) $offer->restaurant_id);

            return $offer;
        });
    }

    public function update(OfferData $data, Offer $offer): Offer
    {
        return DB::transaction(function () use ($data, $offer) {
            $oldAttributes = $offer->getAttributes();
            tap($offer)->update($data->onlyModelAttributes());
            $this->activityLogService->logOfferUpdated($offer, (int) $offer->restaurant_id, $oldAttributes);

            return $offer;
        });
    }
}
