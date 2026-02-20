<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\CategoryData;
use Modules\Resturants\Models\Category;

final class CategoryService
{
    public function store(CategoryData $data): Category
    {
        return DB::transaction(static function () use ($data) {
            return Category::create($data->onlyModelAttributes());
        });
    }

    public function update(CategoryData $data, Category $category): Category
    {
        return DB::transaction(static function () use ($data, $category) {
            tap($category)->update($data->onlyModelAttributes());

            return $category;
        });
    }
}
