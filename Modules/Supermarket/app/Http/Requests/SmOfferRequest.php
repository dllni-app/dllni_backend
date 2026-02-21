<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'offerType' => 'sometimes|required|string|max:255',
            'discountValue' => 'nullable|numeric|min:0',
            'discountPercent' => 'nullable|integer|min:0|max:100',
            'startsAt' => 'nullable|date',
            'endsAt' => 'nullable|date|after_or_equal:startsAt',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
