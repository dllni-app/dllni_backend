<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\WorkerData;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;

final class WorkerService
{
    public function store(WorkerData $data): Worker
    {
        return DB::transaction(static function () use ($data) {
            $worker = Worker::create($data->onlyModelAttributes());

            return $worker;
        });
    }

    public function update(WorkerData $data, Worker $worker): Worker
    {
        return DB::transaction(static function () use ($data, $worker) {
            tap($worker)->update($data->onlyModelAttributes());

            return $worker;
        });
    }
}
