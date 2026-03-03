<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Resturants\Http\Requests\ReviewRequests\ReviewFilterRequest;
use Modules\Resturants\Http\Resources\ReviewResource;
use Modules\Resturants\Models\Review;

final class ReviewController
{
    public function index(ReviewFilterRequest $request): AnonymousResourceCollection
    {
        $reviews = Review::getQuery()
            ->with(['user', 'order', 'restaurant'])
            ->paginate($request->get('perPage', 10));

        return ReviewResource::collection($reviews);
    }

    public function show(Review $review): ReviewResource
    {
        $review->load(['user', 'order', 'restaurant']);

        return ReviewResource::make($review);
    }
}
