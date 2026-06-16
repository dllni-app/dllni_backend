<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Data\CleaningServiceData;
use Modules\Cleaning\Models\CleaningService;

final class CleaningServiceService
{
    public function store(CleaningServiceData $data): CleaningService
    {
        return DB::transaction(static function () use ($data) {
            $payload = $data->onlyModelAttributes();
            $payload['slug'] = self::uniqueSlug((string) ($payload['name'] ?? 'service'));

            $service = CleaningService::create($payload);

            return $service;
        });
    }

    public function update(CleaningServiceData $data, CleaningService $service): CleaningService
    {
        return DB::transaction(static function () use ($data, $service) {
            $payload = $data->onlyModelAttributes();

            if (array_key_exists('name', $payload)) {
                $payload['slug'] = self::uniqueSlug((string) $payload['name'], $service->id);
            }

            tap($service)->update($payload);

            return $service;
        });
    }

    private static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'service';
        }

        $slug = $base;
        $counter = 2;

        while (CleaningService::query()
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
