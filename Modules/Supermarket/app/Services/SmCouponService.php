<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmCouponData;
use Modules\Supermarket\Models\SmCoupon;

final class SmCouponService
{
    public function store(SmCouponData $data): SmCoupon
    {
        return DB::transaction(static function () use ($data) {
            $coupon = SmCoupon::create($data->onlyModelAttributes());

            return $coupon;
        });
    }

    public function update(SmCouponData $data, SmCoupon $coupon): SmCoupon
    {
        return DB::transaction(static function () use ($data, $coupon) {
            tap($coupon)->update($data->onlyModelAttributes());

            return $coupon;
        });
    }
}
