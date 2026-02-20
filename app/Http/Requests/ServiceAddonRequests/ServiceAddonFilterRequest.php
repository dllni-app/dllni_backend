<?php

declare(strict_types=1);

namespace App\Http\Requests\ServiceAddonRequests;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceAddonFilterRequest extends FormRequest
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
            'filter.pricingType' => 'sometimes|string|in:fixed,percentage',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,pricingType,-pricingType,createdAt,-createdAt',
        ];
    }
}
