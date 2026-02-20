<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\ReviewRequests;

use Illuminate\Foundation\Http\FormRequest;

final class ReviewFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.ratingMin' => 'sometimes|integer|min:1|max:5',
            'filter.ratingMax' => 'sometimes|integer|min:1|max:5|gte:filter.ratingMin',
            'filter.dateFrom' => 'sometimes|date',
            'filter.dateTo' => 'sometimes|date|after_or_equal:filter.dateFrom',
            'sort' => 'sometimes|string|in:rating,-rating,created_at,-created_at',
        ];
    }
}
