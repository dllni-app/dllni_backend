<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ServicePricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'propertyType' => 'required|string|max:255',
            'livingRoomSize' => 'nullable|string|max:255',
            'basePrice' => 'required|numeric|min:0',
            'pricePerSqm' => 'nullable|numeric|min:0',
            'minHours' => 'nullable|numeric|min:0',
        ];
    }
}
