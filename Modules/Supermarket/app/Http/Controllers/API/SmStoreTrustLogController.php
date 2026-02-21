<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmStoreTrustLogRequests\SmStoreTrustLogFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreTrustLogResource;
use Modules\Supermarket\Models\SmStoreTrustLog;

final class SmStoreTrustLogController
{
    public function index(SmStoreTrustLogFilterRequest $request): AnonymousResourceCollection
    {
        $logs = SmStoreTrustLog::getQuery()->paginate($request->get('perPage', 20));

        return SmStoreTrustLogResource::collection($logs);
    }

    public function show(SmStoreTrustLog $smStoreTrustLog): SmStoreTrustLogResource
    {
        return SmStoreTrustLogResource::make($smStoreTrustLog->load('triggeredByUser'));
    }
}
