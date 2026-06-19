<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Support\Broadcast\BroadcastAfterResponse;
use Illuminate\Http\JsonResponse;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\User\Http\Requests\UserCleaningOrderCompletionExtendTimeRequest;
use Modules\User\Services\UserCleaningOrderService;

final class UserCleaningOrderCompletionExtendTimeController
{
    public function __invoke(UserCleaningOrderCompletionExtendTimeRequest $request, int $order, UserCleaningOrderService $service): JsonResponse
    {
        $model = CleaningBooking::query()
            ->where('customer_id', $request->user()->id)
            ->findOrFail($order);

        $note = $request->customerMessage();
        $result = $service->requestCompletionExtension(
            booking: $model,
            additionalMinutes: (int) $request->validated('additionalMinutes'),
        );
        $updated = $result['booking'];

        if ($note !== null) {
            $warning = CleaningTimeWarning::query()
                ->where('booking_id', $updated->id)
                ->where('booking_type', $updated->getMorphClass())
                ->latest('id')
                ->first();

            $warning?->update(['customer_message' => $note]);

            BroadcastAfterResponse::send(new CompletionDecisionMade(
                $updated->id,
                $updated->worker_id,
                'extension_requested',
                $note,
                now()->toIso8601String(),
            ));
        }

        $updated->load(['worker.user', 'timeWarnings', 'disputes', 'addons', 'billingPolicy']);

        return CleaningBookingResource::make($updated)->additional([
            'message' => __('Extension request sent successfully.'),
            'extensionPricing' => $result['extensionPricing'],
        ])->response();
    }
}
