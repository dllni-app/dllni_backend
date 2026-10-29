<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningV2VisitWorkSegmentService;

/** A worker can create/end only their own explicitly allocated task segments. */
final class WorkerV2TaskWorkController
{
    public function __construct(private readonly CleaningV2VisitWorkSegmentService $work) {}

    public function __invoke(Request $request, int $booking, int $visit, string $action): JsonResponse
    {
        $input = $request->validate([
            'taskKey' => ['required', 'string', 'max:255'],
        ]);
        $order = CleaningBooking::query()->findOrFail($booking);
        $session = $order->sessions()->where('sequence', $visit)->firstOrFail();
        $data = match ($action) {
            'start' => $this->work->workerStart($request->user(), $order, $session, $input['taskKey']),
            'finish' => $this->work->workerFinish($request->user(), $order, $session, $input['taskKey']),
            default => abort(404),
        };

        return response()->json(['success' => true, 'data' => $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
