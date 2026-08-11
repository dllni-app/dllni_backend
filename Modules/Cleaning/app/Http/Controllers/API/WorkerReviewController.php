<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Enums\WorkerCustomerRatingType;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Cleaning\Http\Requests\WorkerReviewIndexRequest;
use Modules\Cleaning\Http\Resources\WorkerReviewResource;

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
        $totalCount = (clone $query)->count();
        $ratingCounts = $this->ratingCounts($query);
        $paginator = $query
            ->with(['customer:id,name'])
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => WorkerReviewResource::collection($paginator->getCollection())->resolve($request),
            'meta' => [
                'averageRating' => round($averageRating, 1),
                'totalCount' => $totalCount,
                'ratingCounts' => $ratingCounts,
                'rating_counts' => $ratingCounts,
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
        return WorkerCustomerRating::query()
            ->where('worker_id', $worker->id)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value);
    }

    private function ratingCounts(Builder $query): array
    {
        $counts = [];

        for ($rating = 1; $rating <= 5; $rating++) {
            $counts[(string) $rating] = (clone $query)->where('rating', $rating)->count();
        }

        return $counts;
    }
}
