<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmSmartListData;
use Modules\Supermarket\Models\SmSmartList;

final class SmSmartListService
{
    public function store(SmSmartListData $data): SmSmartList
    {
        return DB::transaction(static function () use ($data) {
            $list = SmSmartList::create($data->onlyModelAttributes());

            return $list;
        });
    }

    public function update(SmSmartListData $data, SmSmartList $list): SmSmartList
    {
        return DB::transaction(static function () use ($data, $list) {
            tap($list)->update($data->onlyModelAttributes());

            return $list;
        });
    }
}
