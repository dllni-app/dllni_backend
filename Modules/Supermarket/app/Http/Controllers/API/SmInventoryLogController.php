<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmInventoryLogRequests\SmInventoryLogFilterRequest;
use Modules\Supermarket\Http\Resources\SmInventoryLogResource;
use Modules\Supermarket\Models\SmInventoryLog;

final class SmInventoryLogController
{
    public function index(SmInventoryLogFilterRequest $request): AnonymousResourceCollection
    {
        $logs = SmInventoryLog::getQuery()->paginate($request->get('perPage', 20));

        return SmInventoryLogResource::collection($logs);
    }

    public function show(SmInventoryLog $smInventoryLog): SmInventoryLogResource
    {
        return SmInventoryLogResource::make($smInventoryLog->load(['product', 'user']));
    }
}
