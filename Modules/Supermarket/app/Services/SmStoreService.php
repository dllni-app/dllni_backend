<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmStoreData;
use Modules\Supermarket\Models\SmStore;

final class SmStoreService
{
    public function store(SmStoreData $data): SmStore
    {
        return DB::transaction(static function () use ($data): SmStore {
            $store = SmStore::create($data->onlyModelAttributes());

            return $store;
        });
    }

    public function update(SmStoreData $data, SmStore $smStore): SmStore
    {
        return DB::transaction(static function () use ($data, $smStore): SmStore {
            tap($smStore)->update($data->onlyModelAttributes());

            return $smStore;
        });
    }
}
