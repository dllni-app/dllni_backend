<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\OfferRequests;

use Illuminate\Foundation\Http\FormRequest;

final class OfferFilterRequest extends FormRequest
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
            'filter.isActive' => 'sometimes|boolean',
            'filter.startsAtFrom' => 'sometimes|date',
            'filter.endsAtTo' => 'sometimes|date',
            'sort' => 'sometimes|string|in:name,-name,starts_at,-starts_at,ends_at,-ends_at,created_at,-created_at',
        ];
    }
}
