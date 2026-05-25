<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrdersIndexRequest;

final class UserCleaningOrdersController
{
    public function __invoke(UserCleaningOrdersIndexRequest $request): AnonymousResourceCollection
    {
        $orders = CleaningBooking::getQuery()
            ->where('customer_id', $request->user()->id)
            ->with(['worker.user', 'timeWarnings', 'disputes', 'services'])
            ->paginate((int) $request->validated('perPage', 20));

        return CleaningBookingResource::collection($orders);
    }
}
