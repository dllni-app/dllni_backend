<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\BookingReview;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Requests\WorkerReviewIndexRequest;
use Modules\Cleaning\Http\Resources\WorkerReviewResource;
use Modules\Cleaning\Models\CleaningBooking;

final class WorkerReviewController
{
    public function index(WorkerReviewIndexRequest $request): JsonResponse
    {
        $worker = $this->worker();

        if (! $worker) {
            return response()->json([
                'message' => 'User must have an associated worker.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validated();
        $perPage = (int) ($validated['perPage'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        $query = $this->reviewsQuery($worker);
        $averageRating = (float) (clone $query)->avg('rating');
        $paginator = $query
            ->with(['customer:id,name'])
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => WorkerReviewResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'averageRating' => round($averageRating, 1),
                'totalCount' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    private function worker(): ?Worker
    {
        return auth()->user()?->worker;
    }

    private function reviewsQuery(Worker $worker): Builder
    {
        return BookingReview::query()
            ->whereHasMorph('booking', [CleaningBooking::class], function (Builder $bookingQuery) use ($worker): void {
                $bookingQuery->where(function (Builder $workerScope) use ($worker): void {
                    $workerScope->where('worker_id', $worker->id)
                        ->orWhereHas('workerAssignments', function (Builder $assignmentQuery) use ($worker): void {
                            $assignmentQuery
                                ->where('worker_id', $worker->id)
                                ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                        });
                });
            });
    }
}
