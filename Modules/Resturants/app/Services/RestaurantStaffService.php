<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\RestaurantStaffData;
use Modules\Resturants\Models\RestaurantStaff;

final class RestaurantStaffService
{
    public function store(RestaurantStaffData $data): RestaurantStaff
    {
        return DB::transaction(static function () use ($data) {
            return RestaurantStaff::create($data->onlyModelAttributes());
        });
    }

    public function update(RestaurantStaffData $data, RestaurantStaff $staff): RestaurantStaff
    {
        return DB::transaction(static function () use ($data, $staff) {
            tap($staff)->update($data->onlyModelAttributes());

            return $staff;
        });
    }
}
