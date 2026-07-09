<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingWorkerSecurityCodeService;
use Modules\User\Http\Requests\UserCleaningOrderStartVerificationConfirmRequest;

final class UserCleaningOrderStartVerificationConfirmController
{
    public function __invoke(UserCleaningOrderStartVerificationConfirmRequest $request, int $order, CleaningBookingWorkerSecurityCodeService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $updated = $service->confirmForCustomer($model, $request->validated('code'));
        $updated->load(['worker.user', 'workerAssignments.worker.user', 'rooms.assignedWorker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Security code verified successfully.'),
        ])->response();
    }
}
