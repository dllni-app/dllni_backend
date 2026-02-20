<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ServiceAddonData;
use App\Models\ServiceAddon;
use Illuminate\Support\Facades\DB;

final class ServiceAddonService
{
    public function store(ServiceAddonData $data): ServiceAddon
    {
        return DB::transaction(static function () use ($data) {
            $addon = ServiceAddon::create($data->onlyModelAttributes());

            return $addon;
        });
    }

    public function update(ServiceAddonData $data, ServiceAddon $addon): ServiceAddon
    {
        return DB::transaction(static function () use ($data, $addon) {
            tap($addon)->update($data->onlyModelAttributes());

            return $addon;
        });
    }
}
