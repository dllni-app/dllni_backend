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
        $eventPayloadRequested = $this->shouldValidateEventPayload();

        return [
            'propertyType' => ['sometimes', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'propertyDetails' => ['sometimes', 'array:address,location_name,bedrooms,rooms,bathrooms,kitchens,living_room_size,eventType,guestCount,venueType,specialRequirement,notes'],
            'propertyDetails.address' => ['sometimes', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.eventType' => [Rule::requiredIf($eventPayloadRequested), 'string', Rule::in(UserCleaningOrderEstimationService::EVENT_TYPES)],
            'propertyDetails.guestCount' => [Rule::requiredIf($eventPayloadRequested), 'integer', 'min:1', 'max:5000'],
            'propertyDetails.venueType' => [Rule::requiredIf($eventPayloadRequested), 'string', Rule::in($this->availableVenueTypes())],
            'propertyDetails.specialRequirement' => ['nullable', 'string', 'max:255'],
            'propertyDetails.notes' => ['nullable', 'string', 'max:2000'],
            'serviceIds' => ['sometimes', 'array', 'min:1'],
            'serviceIds.*' => ['integer', 'distinct', 'exists:cleaning_services,id'],
            'scheduledDate' => ['sometimes', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['sometimes', 'date_format:H:i'],
            'addressLatitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['sometimes', 'nullable', 'exists:workers,id'],
            'numberOfWorkers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'genderPreference' => ['sometimes', 'nullable', 'string', Rule::in(['any', 'male', 'female'])],
            'estimatedSqm' => ['prohibited'],
            'estimatedHours' => ['prohibited'],
            'totalHours' => ['prohibited'],
            'basePrice' => ['prohibited'],
            'travelFee' => ['prohibited'],
            'addonsTotal' => ['prohibited'],
            'totalPrice' => ['prohibited'],
        ];
    }

    private function shouldValidateEventPayload(): bool
    {
        if (mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return true;
        }

        return $this->has('propertyDetails.eventType')
            || $this->has('propertyDetails.guestCount')
            || $this->has('propertyDetails.venueType');
    }

    /**
     * @return array<int, string>
     */
    private function availableVenueTypes(): array
    {
        return array_values(array_filter(
            UserCleaningOrderEstimationService::PROPERTY_TYPES,
            static fn (string $type): bool => $type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
        ));
    }
}
