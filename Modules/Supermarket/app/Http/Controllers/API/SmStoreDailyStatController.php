<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Http\Requests\SmStoreDailyStatRequests\SmStoreDailyStatFilterRequest;
use Modules\Supermarket\Http\Resources\SmStoreDailyStatResource;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SmStoreDailyStatController
{
    public function index(SmStoreDailyStatFilterRequest $request): AnonymousResourceCollection
    {
        $stats = SmStoreDailyStat::getQuery()->paginate($request->get('perPage', 20));

        return SmStoreDailyStatResource::collection($stats);
    }

    public function show(SmStoreDailyStat $smStoreDailyStat): SmStoreDailyStatResource
    {
        return SmStoreDailyStatResource::make($smStoreDailyStat);
    }
}
