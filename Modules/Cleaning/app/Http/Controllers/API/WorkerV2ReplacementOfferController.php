<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionReplacementRequest;
use Modules\Cleaning\Services\CleaningV2VisitReplacementRequestService;

final class WorkerV2ReplacementOfferController
{
    public function __construct(private readonly CleaningV2VisitReplacementRequestService $replacements) {}

    /** Only offers explicitly addressed to this authenticated worker are visible. */
    public function index(Request $request): JsonResponse
    {
        $worker = Worker::query()->where('user_id', $request->user()->id)->first();
        if ($worker === null) {
            abort(403, 'Worker account required.');
        }

        $offers = CleaningBookingSessionReplacementRequest::query()
            ->where('candidate_worker_id', $worker->id)
            ->where('status', 'offered')
            ->whereNotNull('approved_at')
            ->whereHas('session', fn ($query) => $query
                ->whereNull('work_started_at')
                ->whereNotIn('status', ['completed', 'cancelled', 'skipped', 'superseded']))
            ->with('session:id,cleaning_booking_id,sequence,scheduled_date,scheduled_time')
            ->orderByDesc('id')->limit(30)->get();

        return response()->json(['success' => true, 'data' => [
            'offers' => $offers->map(static fn ($offer): array => [
                'requestId' => (int) $offer->id,
                'bookingId' => (int) $offer->session?->cleaning_booking_id,
                'visitSequence' => (int) $offer->session?->sequence,
                'scheduledDate' => $offer->session?->scheduled_date?->toDateString(),
                'scheduledTime' => mb_substr((string) $offer->session?->scheduled_time, 0, 5),
                'status' => 'offered',
            ])->values()->all(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request, int $booking, int $visit, int $replacement): JsonResponse
    {
        $order = CleaningBooking::query()->findOrFail($booking);
        $session = CleaningBookingSession::query()->where('cleaning_booking_id', $order->id)
            ->where('sequence', $visit)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->replacements->accept(
            $request->user(), $order, $session, $replacement,
        )])->header('Cache-Control', 'private, no-store');
    }
}
