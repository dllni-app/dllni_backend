<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmCategoryData;
use Modules\Supermarket\Models\SmCategory;

final class SmCategoryService
{
    public function store(SmCategoryData $data): SmCategory
    {
        return DB::transaction(static function () use ($data) {
            $category = SmCategory::create($data->onlyModelAttributes());

            return $category;
        });
    }

    public function update(SmCategoryData $data, SmCategory $category): SmCategory
    {
        return DB::transaction(static function () use ($data, $category) {
            tap($category)->update($data->onlyModelAttributes());

            return $category;
        });
    }
}
