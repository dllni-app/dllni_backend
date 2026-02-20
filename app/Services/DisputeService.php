<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\DisputeData;
use App\Models\Dispute;
use Illuminate\Support\Facades\DB;

final class DisputeService
{
    public function store(DisputeData $data): Dispute
    {
        return DB::transaction(static function () use ($data) {
            return Dispute::create($data->onlyModelAttributes());
        });
    }

    public function update(DisputeData $data, Dispute $dispute): Dispute
    {
        return DB::transaction(static function () use ($data, $dispute) {
            tap($dispute)->update($data->onlyModelAttributes());

            return $dispute;
        });
    }
}
