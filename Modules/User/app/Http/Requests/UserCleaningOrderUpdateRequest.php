<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderUpdateRequest extends FormRequest
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
            'propertyDetails' => ['sometimes', 'array'],
            'propertyDetails.address' => ['sometimes', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:1', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'estimatedSqm' => ['sometimes', 'numeric', 'min:1'],
            'totalHours' => ['sometimes', 'numeric', 'min:1', 'max:24'],
            'scheduledDate' => ['sometimes', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['sometimes', 'date_format:H:i'],
            'addressLatitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['sometimes', 'nullable', 'exists:workers,id'],
            'basePrice' => ['sometimes', 'numeric', 'min:0'],
            'travelFee' => ['sometimes', 'numeric', 'min:0'],
            'addonsTotal' => ['sometimes', 'numeric', 'min:0'],
            'totalPrice' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
