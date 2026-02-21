<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmOrderStatusLogRequests\SmOrderStatusLogFilterRequest;
use Modules\Supermarket\Http\Resources\SmOrderStatusLogResource;
use Modules\Supermarket\Models\SmOrderStatusLog;

final class SmOrderStatusLogController
{
    public function index(SmOrderStatusLogFilterRequest $request): AnonymousResourceCollection
    {
        $logs = SmOrderStatusLog::getQuery()->paginate($request->get('perPage', 20));

        return SmOrderStatusLogResource::collection($logs);
    }

    public function show(SmOrderStatusLog $smOrderStatusLog): SmOrderStatusLogResource
    {
        return SmOrderStatusLogResource::make($smOrderStatusLog->load(['order', 'changedByUser']));
    }
}
