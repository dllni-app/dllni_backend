<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOrderDisputeData;
use Modules\Supermarket\Models\SmOrderDispute;

final class SmOrderDisputeService
{
    public function store(SmOrderDisputeData $data): SmOrderDispute
    {
        return DB::transaction(static function () use ($data) {
            $dispute = SmOrderDispute::create($data->onlyModelAttributes());

            return $dispute;
        });
    }

    public function update(SmOrderDisputeData $data, SmOrderDispute $dispute): SmOrderDispute
    {
        return DB::transaction(static function () use ($data, $dispute) {
            tap($dispute)->update($data->onlyModelAttributes());

            return $dispute;
        });
    }
}
