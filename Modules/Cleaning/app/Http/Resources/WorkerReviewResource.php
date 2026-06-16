<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Resources;

use App\Models\BookingReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BookingReview
 */
final class WorkerReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerName' => (string) ($this->customer?->name ?? ''),
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'createdAt' => $this->created_at?->toJSON(),
        ];
    }
}
