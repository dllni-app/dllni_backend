<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPreferredWorkerRejectionDecisionService;
use Modules\User\Http\Requests\UserCleaningPreferredWorkerRejectionDecisionRequest;

final class UserCleaningPreferredWorkerRejectionDecisionController
{
    public function pending(
        Request $request,
        CleaningPreferredWorkerRejectionDecisionService $service,
    ): AnonymousResourceCollection {
        return CleaningBookingResource::collection(
            $service->pendingForCustomer((int) $request->user()->id)
        );
    }

    public function decide(
        UserCleaningPreferredWorkerRejectionDecisionRequest $request,
        int $order,
        CleaningPreferredWorkerRejectionDecisionService $service,
    ): CleaningBookingResource {
        $booking = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        return CleaningBookingResource::make(
            $service->decide(
                booking: $booking,
                customerId: (int) $request->user()->id,
                decision: (string) $request->validated('decision'),
            )
        );
    }
}
