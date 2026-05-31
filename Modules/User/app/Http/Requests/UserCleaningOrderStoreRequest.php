<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderStoreRequest extends FormRequest
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
        $isEventAssistance = $this->isEventAssistanceRequested();

        return [
            'propertyType' => ['required', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'propertyDetails' => ['required', 'array:address,location_name,bedrooms,rooms,bathrooms,kitchens,balconies,living_room_size,room_size_breakdown,eventType,guestCount,venueType,specialRequirement,notes'],
            'propertyDetails.address' => ['required', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.balconies' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.room_size_breakdown' => ['nullable', 'array:bedroom,bathroom,kitchen,living_room,balcony'],
            'propertyDetails.room_size_breakdown.bedroom' => ['required_with:propertyDetails.room_size_breakdown', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.bathroom' => ['required_with:propertyDetails.room_size_breakdown', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.kitchen' => ['required_with:propertyDetails.room_size_breakdown', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.living_room' => ['required_with:propertyDetails.room_size_breakdown', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.balcony' => ['required_with:propertyDetails.room_size_breakdown', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.bedroom.small' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.bedroom.medium' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.bedroom.large' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.bathroom.small' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.bathroom.medium' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.bathroom.large' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.kitchen.small' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.kitchen.medium' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.kitchen.large' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.living_room.small' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.living_room.medium' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.living_room.large' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.balcony.small' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.balcony.medium' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.balcony.large' => ['required_with:propertyDetails.room_size_breakdown', 'integer', 'min:0'],
            'propertyDetails.eventType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in(UserCleaningOrderEstimationService::EVENT_TYPES)],
            'propertyDetails.guestCount' => [Rule::requiredIf($isEventAssistance), 'integer', 'min:1', 'max:5000'],
            'propertyDetails.venueType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in($this->availableVenueTypes())],
            'propertyDetails.specialRequirement' => ['nullable', 'string', 'max:255'],
            'propertyDetails.notes' => ['nullable', 'string', 'max:2000'],
            'serviceIds' => [Rule::requiredIf($isEventAssistance), 'array', 'min:1'],
            'serviceIds.*' => ['integer', 'distinct', 'exists:cleaning_services,id'],
            'scheduledDate' => ['required', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'addressLatitude' => ['nullable', 'required_with:preferredWorkerId', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'required_with:preferredWorkerId', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
            'numberOfWorkers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'genderPreference' => ['nullable', 'string', Rule::in(['any', 'male', 'female'])],
            'estimatedSqm' => ['prohibited'],
            'estimatedHours' => ['prohibited'],
            'totalHours' => ['prohibited'],
            'basePrice' => ['prohibited'],
            'travelFee' => ['prohibited'],
            'addonsTotal' => ['prohibited'],
            'totalPrice' => ['prohibited'],
            'cancellationPolicyId' => ['nullable', 'exists:cancellation_policies,id'],
            'billingPolicyId' => ['nullable', 'exists:cleaning_billing_policies,id'],
            'termsAccepted' => ['required', 'accepted'],
        ];
    }

    private function isEventAssistanceRequested(): bool
    {
        return mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
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
