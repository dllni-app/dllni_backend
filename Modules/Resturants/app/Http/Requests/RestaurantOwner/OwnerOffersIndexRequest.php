<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerOffersIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'sometimes|string|in:active,scheduled,expired,all',
            'search' => 'sometimes|string|max:255',
            'dateFrom' => 'sometimes|date',
            'dateTo' => 'sometimes|date|after_or_equal:dateFrom',
            'sort' => 'sometimes|string|in:created_at,-created_at,name,-name,discount_value,-discount_value,performance,-performance',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
