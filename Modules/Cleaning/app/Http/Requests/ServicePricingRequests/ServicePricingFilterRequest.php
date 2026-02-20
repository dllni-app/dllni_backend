<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\ServicePricingRequests;

use Illuminate\Foundation\Http\FormRequest;

final class ServicePricingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.propertyType' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:basePrice,-basePrice,propertyType,-propertyType,createdAt,-createdAt',
        ];
    }
}
