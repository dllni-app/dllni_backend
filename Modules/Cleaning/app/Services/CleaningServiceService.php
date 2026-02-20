<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Data\CleaningServiceData;
use Modules\Cleaning\Models\CleaningService;

final class CleaningServiceService
{
    public function store(CleaningServiceData $data): CleaningService
    {
        return DB::transaction(static function () use ($data) {
            $service = CleaningService::create($data->onlyModelAttributes());

            return $service;
        });
    }

    public function update(CleaningServiceData $data, CleaningService $service): CleaningService
    {
        return DB::transaction(static function () use ($data, $service) {
            tap($service)->update($data->onlyModelAttributes());

            return $service;
        });
    }
}
