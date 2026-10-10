<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningLastHourCoverageService;
use Modules\User\Http\Resources\UserCleaningBookingResource;

final class UserCleaningLastHourTeamDecisionController
{
    public function __invoke(Request $request, int $order, CleaningLastHourCoverageService $service): JsonResponse
    {
        $validated = $request->validate([
            'choice' => ['required', Rule::in(['all_tasks', 'assigned_only'])],
            'workerId' => ['nullable', 'integer', 'min:1'],
        ]);

        $booking = CleaningBooking::query()
            ->where('customer_id', (int) $request->user()->id)
            ->findOrFail($order);
        $updated = $service->decide(
            $booking,
            (string) $validated['choice'],
            isset($validated['workerId']) ? (int) $validated['workerId'] : null,
        );

        return response()->json([
            'data' => UserCleaningBookingResource::make($updated),
            'message' => 'تم حفظ قرار توزيع المهام.',
        ]);
    }
}
