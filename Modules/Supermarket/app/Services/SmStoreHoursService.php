<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Supermarket\Data\SmStoreHoursData;
use Modules\Supermarket\Models\SmStoreHours;

final class SmStoreHoursService
{
    public function store(SmStoreHoursData $data): SmStoreHours
    {
        return DB::transaction(static function () use ($data) {
            $attributes = self::normalizeAttributes($data->onlyModelAttributes());
            $hours = SmStoreHours::create($attributes);

            return $hours;
        });
    }

    public function update(SmStoreHoursData $data, SmStoreHours $hours): SmStoreHours
    {
        return DB::transaction(static function () use ($data, $hours) {
            $attributes = self::normalizeAttributes($data->onlyModelAttributes());
            $hours->update($attributes);

            return $hours;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function normalizeAttributes(array $attributes): array
    {
        return collect($attributes)
            ->mapWithKeys(fn ($value, string $key): array => [Str::snake($key) => $value])
            ->all();
    }
}
