<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DiscoverRestaurantsRequest extends FormRequest
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'filter.openNow' => ['sometimes', 'boolean'],
            'filter.hasOffers' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:rating,nearest,fastest'],
        ];
    }
}
