<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\PromoCodeData;
use Modules\Resturants\Models\PromoCode;

final class PromoCodeService
{
    public function store(PromoCodeData $data): PromoCode
    {
        return DB::transaction(static function () use ($data) {
            return PromoCode::create($data->onlyModelAttributes());
        });
    }

    public function update(PromoCodeData $data, PromoCode $promoCode): PromoCode
    {
        return DB::transaction(static function () use ($data, $promoCode) {
            tap($promoCode)->update($data->onlyModelAttributes());

            return $promoCode;
        });
    }
}
