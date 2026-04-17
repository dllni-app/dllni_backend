<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DiscoverSupermarketStoresRequest extends FormRequest
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:255'],
            'query' => ['sometimes', 'string', 'max:255'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'average_rating_min' => ['sometimes', 'numeric', 'between:1,5'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'filter.openNow' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.isActive' => ['sometimes', 'boolean'],
            'filter.isFeatured' => ['sometimes', 'boolean'],
            'filter.averageRatingMin' => ['sometimes', 'numeric', 'between:1,5'],
            'sort' => ['sometimes', 'string', 'in:rating,nearest,nearestBy,alphabet,alphabetical'],
        ];
    }
}
