<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TravelCostConfigData;
use App\Models\TravelCostConfig;
use Illuminate\Support\Facades\DB;

final class TravelCostConfigService
{
    public function store(TravelCostConfigData $data): TravelCostConfig
    {
        return DB::transaction(static function () use ($data) {
            $config = TravelCostConfig::create($data->onlyModelAttributes());

            return $config;
        });
    }

    public function update(TravelCostConfigData $data, TravelCostConfig $config): TravelCostConfig
    {
        return DB::transaction(static function () use ($data, $config) {
            tap($config)->update($data->onlyModelAttributes());

            return $config;
        });
    }
}
