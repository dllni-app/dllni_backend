<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;
use Modules\Cleaning\Services\CleaningBookingWorkerSecurityCodeService;
use Modules\User\Http\Requests\UserCleaningOrderStartVerificationConfirmRequest;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningOrderStartVerificationConfirmController
{
    public function __invoke(
        UserCleaningOrderStartVerificationConfirmRequest $request,
        int $order,
        CleaningBookingWorkerSecurityCodeService $service,
        CleaningBookingSessionLifecycleService $sessionLifecycle,
    ): JsonResponse {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $code = (string) $request->validated('code');
        $matchedSession = $sessionLifecycle->confirmMatchingSecurityCodeForBooking(
            $model,
            (int) $request->user()->id,
            $code,
        );

        $updated = $matchedSession instanceof \Modules\Cleaning\Models\CleaningBookingSession
            ? ($model->fresh() ?? $model)
            : $service->confirmForCustomer($model, $code);
        $updated->load(['worker.user', 'workerAssignments.worker.user', 'rooms.assignedWorker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return UserCleaningBookingResource::make($updated)->additional([
            'message' => __('Security code verified successfully.'),
        ])->response();
    }
}
