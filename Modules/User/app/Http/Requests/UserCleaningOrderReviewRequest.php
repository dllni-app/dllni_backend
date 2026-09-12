<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Regular cleaning keeps the existing workerId + rating payload.
            // Event assistance uses reviews[] so each unique participating worker
            // receives an independent rating and optional comment.
            'workerId' => ['nullable', 'integer', 'exists:workers,id'],
            'rating' => ['nullable', 'required_without:reviews', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'reviews' => ['nullable', 'required_without:rating', 'array', 'min:1'],
            'reviews.*.workerId' => ['required_with:reviews', 'integer', 'distinct', 'exists:workers,id'],
            'reviews.*.rating' => ['required_with:reviews', 'integer', 'min:1', 'max:5'],
            'reviews.*.comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
