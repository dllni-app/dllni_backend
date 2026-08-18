<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Support\Facades\Auth;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderShowController
{
    public function __invoke(int $order): UserCleaningBookingResource
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
                'addons',
                'billingPolicy',
            ])
            ->findOrFail($order);

        return UserCleaningBookingResource::make($model);
    }
}
