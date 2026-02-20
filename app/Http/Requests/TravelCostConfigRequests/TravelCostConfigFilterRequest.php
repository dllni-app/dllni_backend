<?php

declare(strict_types=1);

namespace App\Http\Requests\TravelCostConfigRequests;

use Illuminate\Foundation\Http\FormRequest;

final class TravelCostConfigFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.isActive' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,maxKm,-maxKm,createdAt,-createdAt',
        ];
    }
}
