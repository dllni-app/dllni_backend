<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\RestaurantDocumentData;
use Modules\Resturants\Models\RestaurantDocument;

final class RestaurantDocumentService
{
    public function store(RestaurantDocumentData $data): RestaurantDocument
    {
        return DB::transaction(static function () use ($data) {
            return RestaurantDocument::create($data->onlyModelAttributes());
        });
    }

    public function update(RestaurantDocumentData $data, RestaurantDocument $document): RestaurantDocument
    {
        return DB::transaction(static function () use ($data, $document) {
            tap($document)->update($data->onlyModelAttributes());

            return $document;
        });
    }
}
