<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Http\Requests\UserCleaningOrderCompletionRejectRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCompletionRejectController
{
    public function __invoke(UserCleaningOrderCompletionRejectRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $note = $request->completionRejectionMessage();
        $updated = $service->rejectCompletion($model);

        if ($note !== null) {
            $updated->update([
                'customer_completion_rejection_message' => $note,
                'completion_rejected_at' => now(),
            ]);

            $updated = $updated->fresh() ?? $updated;

            BroadcastAfterResponse::send(new CompletionDecisionMade(
                $updated->id,
                $updated->worker_id,
                'rejected',
                $note,
                now()->toIso8601String(),
            ));
        }

        $updated->load(['worker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Completion rejection sent successfully.'),
        ])->response();
    }
}
