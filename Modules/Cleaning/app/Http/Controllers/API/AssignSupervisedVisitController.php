<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;

final class AssignSupervisedVisitController
{
    public function __construct(private readonly CleaningSupervisorAssignmentService $assignments) {}

    public function __invoke(Request $request, int $booking, int $visit): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $this->assignments->canAssign($actor), 403);

        $data = $request->validate([
            'supervisorUserId' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'min:10', 'max:500'],
        ]);
        $result = $this->assignments->assignVisit(
            $actor,
            $booking,
            $visit,
            (int) $data['supervisorUserId'],
            $data['reason'] ?? null,
        );

        return response()->json(['data' => $result], $result['unchanged'] ? 200 : 201);
    }
}
