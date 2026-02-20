<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\RestaurantOrderDisputeData;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class RestaurantOrderDisputeService
{
    public function store(RestaurantOrderDisputeData $data): RestaurantOrderDispute
    {
        return DB::transaction(static function () use ($data) {
            return RestaurantOrderDispute::create($data->onlyModelAttributes());
        });
    }

    public function update(RestaurantOrderDisputeData $data, RestaurantOrderDispute $dispute): RestaurantOrderDispute
    {
        return DB::transaction(static function () use ($data, $dispute) {
            tap($dispute)->update($data->onlyModelAttributes());

            return $dispute;
        });
    }
}
