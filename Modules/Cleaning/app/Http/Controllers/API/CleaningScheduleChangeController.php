<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningScheduleChangeRequest;
use Modules\Cleaning\Services\CleaningScheduleChangeApprovalService;

final class CleaningScheduleChangeController
{
    public function index(Request $request): JsonResponse
    {
        $worker = $this->worker($request);
        $changes = CleaningScheduleChangeRequest::query()
            ->where('status', 'pending')
            ->whereHas('decisions', fn ($query) => $query->where('worker_id', $worker->id)->where('decision', 'pending'))
            ->with(['booking', 'decisions' => fn ($query) => $query->where('worker_id', $worker->id)])
            ->latest()->get();
        return response()->json(['success' => true, 'data' => ['changeRequests' => $changes]]);
    }

    public function decide(Request $request, CleaningScheduleChangeRequest $changeRequest, CleaningScheduleChangeApprovalService $service): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $resolved = $service->decide($changeRequest, $this->worker($request), $validated['decision'], $validated['reason'] ?? null);
        return response()->json(['success' => true, 'data' => ['changeRequest' => $resolved]]);
    }

    private function worker(Request $request): Worker
    {
        $worker = $request->user()?->worker;
        abort_unless($worker instanceof Worker, 403, 'User must have an associated worker.');
        return $worker;
    }
}
