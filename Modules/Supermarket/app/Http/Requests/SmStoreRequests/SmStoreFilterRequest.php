<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmStoreRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'filter.name' => 'sometimes|string|max:255',
            'filter.slug' => 'sometimes|string|max:255',
            'filter.city' => 'sometimes|string|max:255',
            'filter.neighborhood' => 'sometimes|string|max:255',
            'filter.isActive' => 'sometimes|boolean',
            'filter.isFeatured' => 'sometimes|boolean',
            'filter.suspended' => 'sometimes|boolean',
            'filter.trustScoreMin' => 'sometimes|integer|min:0',
            'filter.trustScoreMax' => 'sometimes|integer|min:0',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,slug,-slug,city,-city,neighborhood,-neighborhood,averageRating,-averageRating,totalReviews,-totalReviews,trustScore,-trustScore,warningCount,-warningCount,createdAt,-createdAt,updatedAt,-updatedAt',
        ];
    }
}
