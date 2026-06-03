<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;

final class UserCleaningOrderShowController
{
    public function __invoke(int $order): CleaningBookingResource
    {
        $model = CleaningBooking::query()
            ->where('customer_id', Auth::id())
            ->with([
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'workerAssignments.worker.user',
                'timeWarnings',
                'disputes',
                'services',
                'addons',
                'billingPolicy',
            ])
            ->findOrFail($order);

        return CleaningBookingResource::make($model);
    }
}
