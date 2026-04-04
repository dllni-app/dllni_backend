<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\CategoryData;
use Modules\Resturants\Models\Category;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class CategoryService
{
    public function store(CategoryData $data): Category
    {
        return DB::transaction(static function () use ($data) {
            $category = Category::create($data->onlyModelAttributes());
            self::attachMedia($data, $category, false);

            return $category;
        });
    }

    public function update(CategoryData $data, Category $category): Category
    {
        return DB::transaction(static function () use ($data, $category) {
            tap($category)->update($data->onlyModelAttributes());
            self::attachMedia($data, $category, true);

            return $category;
        });
    }

    private static function attachMedia(CategoryData $data, Category $category, bool $isUpdate): void
    {
        if ($data->categoryImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->categoryImage, $category, 'category-image');
            } else {
                MediaHelper::uploadMedia($data->categoryImage, $category, 'category-image');
            }
        }
    }
}
