<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Http\Resources\WorkerResource;
use App\Models\Worker;

final class WorkerDetailsController
{
    public function __invoke(Worker $worker): WorkerResource
    {
        $worker->load(['user', 'zones.neighborhood', 'availability', 'media']);

        return WorkerResource::make($worker);
    }
}
