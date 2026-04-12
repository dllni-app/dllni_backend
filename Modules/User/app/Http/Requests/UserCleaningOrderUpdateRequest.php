<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserCleaningOrderEstimationService;

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
            'propertyType' => ['sometimes', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'propertyDetails' => ['sometimes', 'array:address,location_name,bedrooms,rooms,bathrooms,kitchens,living_room_size'],
            'propertyDetails.address' => ['sometimes', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'scheduledDate' => ['sometimes', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['sometimes', 'date_format:H:i'],
            'addressLatitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['sometimes', 'nullable', 'exists:workers,id'],
            'estimatedSqm' => ['prohibited'],
            'estimatedHours' => ['prohibited'],
            'totalHours' => ['prohibited'],
            'basePrice' => ['prohibited'],
            'travelFee' => ['prohibited'],
            'addonsTotal' => ['prohibited'],
            'totalPrice' => ['prohibited'],
        ];
    }
}
