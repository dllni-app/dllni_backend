<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningScheduleChangeRequest;
use Modules\Cleaning\Services\CleaningScheduleChangeApprovalService;

final class UserCleaningScheduleChangeController
{
    public function show(Request $request, CleaningScheduleChangeRequest $changeRequest): JsonResponse
    {
        abort_unless((int) $changeRequest->customer_id === (int) $request->user()->id, 403);
        return response()->json(['success' => true, 'data' => ['changeRequest' => $changeRequest->load('decisions')]]);
    }

    public function resolve(Request $request, CleaningScheduleChangeRequest $changeRequest, CleaningScheduleChangeApprovalService $service): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', 'string', 'in:replace,revert,cancel'],
            'replacementWorkerIds' => ['sometimes', 'array', 'max:20'],
            'replacementWorkerIds.*' => ['integer', 'distinct', 'exists:workers,id'],
        ]);
        $resolved = $service->resolveRejected($changeRequest, $request->user(), $validated['resolution'], (array) ($validated['replacementWorkerIds'] ?? []));
        return response()->json(['success' => true, 'data' => ['changeRequest' => $resolved->fresh('decisions')]]);
    }

    public function replacementOptions(Request $request, CleaningScheduleChangeRequest $changeRequest, CleaningScheduleChangeApprovalService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['workers' => $service->replacementOptions($changeRequest, $request->user())],
        ]);
    }
}
