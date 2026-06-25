<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Http\Requests\CleaningBookingFinishRequest;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingFinishService;
use Throwable;

final class CleaningBookingFinishController
{
    public function __construct(
        private readonly CleaningBookingFinishService $finishService,
    ) {}

    /** @throws Throwable */
    public function __invoke(CleaningBookingFinishRequest $request, CleaningBooking $cleaning_booking): JsonResponse
    {
        try {
            $booking = $request->isSuccessfulFinish()
                ? $this->finishService->finishSuccessfully($cleaning_booking)
                : $this->finishService->openDispute(
                    $cleaning_booking,
                    (string) $request->disputeReasonType(),
                    $request->disputeReasonNote(),
                );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }

        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status->value
            : (string) $booking->status;

        $message = $status === CleaningBookingStatus::UnderDispute->value
            ? 'Cleaning booking has been suspended and assigned to admin review.'
            : 'Cleaning booking finished successfully.';

        $resource = CleaningBookingResource::make($booking)->resolve($request);
        $resource['isTimerRunning'] = false;
        $resource['timerStoppedAt'] = $this->isoDate($booking->timer_stopped_at);
        $resource['canWorkerFinish'] = false;
        $resource['suspendedMessage'] = $status === CleaningBookingStatus::UnderDispute->value ? $message : null;
        $resource['dispute'] = $this->latestDisputePayload($booking);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resource,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestDisputePayload(CleaningBooking $booking): ?array
    {
        $dispute = $booking->relationLoaded('disputes')
            ? $booking->disputes->sortByDesc('id')->first()
            : $booking->disputes()->latest('id')->first();

        if (! $dispute) {
            return null;
        }

        return [
            'id' => $dispute->id,
            'status' => $dispute->status?->value ?? $dispute->status,
            'statusLabel' => $dispute->status?->label() ?? (string) $dispute->status,
            'reasonType' => $dispute->reason_type,
            'reasonLabel' => $dispute->reason_label,
            'reasonNote' => $dispute->reason_note,
            'openedAt' => $dispute->opened_at?->toIso8601String(),
        ];
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_object($value) && method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }
}
