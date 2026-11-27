<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningV3CashSettlementService;

/** Separate V3 daily ledger; legacy transactions/deposit contracts stay intact. */
final class CleaningV3WorkerDailyEarningsController
{
    private function worker(Request $request): Worker
    {
        $actor = $request->user();
        $worker = $actor?->worker;
        abort_unless($actor?->is_active && $worker instanceof Worker
            && (int) $worker->user_id === (int) $actor->id, 403);
        return $worker;
    }

    public function index(Request $request, CleaningV3CashSettlementService $settlement): JsonResponse
    {
        $worker = $this->worker($request);
        $input = $request->validate([
            'scheduledDateFrom' => ['sometimes', 'date_format:Y-m-d'],
            'scheduledDateTo' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:scheduledDateFrom'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $size = (int) ($input['perPage'] ?? 20);
        $ids = CleaningBookingSessionWorkerAssignment::query()
            ->where('worker_id', $worker->id)->whereNotNull('accepted_at')->whereNotNull('work_started_at')
            ->whereHas('session', function ($visit) use ($input): void {
                $visit->where('status', 'completed')
                    ->when(isset($input['scheduledDateFrom']), fn ($q) => $q->whereDate('scheduled_date', '>=', $input['scheduledDateFrom']))
                    ->when(isset($input['scheduledDateTo']), fn ($q) => $q->whereDate('scheduled_date', '<=', $input['scheduledDateTo']))
                    ->whereHas('booking', fn ($booking) => $booking->where('contract_version', 'v3')
                        ->where('property_type', '!=', 'event_assistance')
                        ->whereHas('sessions', fn ($q) => $q->where('status', '!=', 'superseded'), '=', 1));
            })->orderByDesc('id')->pluck('id');
        $rows = [];
        $count = 0;
        $incomplete = 0;
        $total = '0.00';
        foreach ($ids as $id) {
            $entry = $settlement->workerEarning((int) $id, $worker);
            if ($entry === null) {
                continue;
            }
            if ($count >= ($page - 1) * $size && count($rows) < $size) {
                $rows[] = $entry;
            }
            $count++;
            if ($entry['amountSyp'] === null) {
                $incomplete++;
            } else {
                $total = bcadd($total, $entry['amountSyp'], 2);
            }
        }
        return response()->json(['data' => $rows, 'summary' => [
            'settledAmountSyp' => $total, 'currency' => 'SYP', 'incompleteEntries' => $incomplete,
        ], 'meta' => [
            'page' => $page, 'lastPage' => max(1, (int) ceil($count / $size)), 'total' => $count,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $assignment, CleaningV3CashSettlementService $settlement): JsonResponse
    {
        $entry = $settlement->workerEarning($assignment, $this->worker($request));
        abort_if($entry === null, 404);
        return response()->json(['data' => $entry])->header('Cache-Control', 'private, no-store');
    }
}
