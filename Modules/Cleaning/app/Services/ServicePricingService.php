<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Data\ServicePricingData;
use Modules\Cleaning\Models\ServicePricing;

final class ServicePricingService
{
    public function store(ServicePricingData $data): ServicePricing
    {
        return DB::transaction(static function () use ($data) {
            $pricing = ServicePricing::create($data->onlyModelAttributes());

            return $pricing;
        });
    }

    public function update(ServicePricingData $data, ServicePricing $pricing): ServicePricing
    {
        return DB::transaction(static function () use ($data, $pricing) {
            tap($pricing)->update($data->onlyModelAttributes());

            return $pricing;
        });
    }
}
