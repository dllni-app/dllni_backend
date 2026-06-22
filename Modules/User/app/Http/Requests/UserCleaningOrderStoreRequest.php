<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesWorkerRoomAssignments;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderStoreRequest extends FormRequest
{
    use ValidatesWorkerRoomAssignments;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isEventAssistance = $this->isEventAssistanceRequested();

        return [
            'propertyType' => ['required', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'propertyDetails' => ['required', 'array:address,location_name,bedrooms,rooms,bathrooms,toilets,kitchens,balconies,living_room_size,cleaning_mode,room_size_breakdown,eventType,guestCount,venueType,customService,hours,specialRequirement,notes'],
            'propertyDetails.address' => ['required', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.toilets' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.balconies' => ['nullable', 'integer', 'min:0', 'max:100'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.cleaning_mode' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::CLEANING_MODES)],
            'propertyDetails.room_size_breakdown' => ['nullable', 'array:bedroom,bathroom,toilet,kitchen,living_room,balcony,corridor'],
            'propertyDetails.room_size_breakdown.bedroom' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.bathroom' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.toilet' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.kitchen' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.living_room' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.balcony' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.corridor' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.*.small' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.*.medium' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.*.large' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.eventType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in(UserCleaningOrderEstimationService::EVENT_TYPES)],
            'propertyDetails.guestCount' => [Rule::requiredIf($isEventAssistance), 'integer', 'min:1', 'max:5000'],
            'propertyDetails.venueType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in($this->availableVenueTypes())],
            'propertyDetails.customService' => [Rule::requiredIf($isEventAssistance), Rule::prohibitedIf(! $isEventAssistance), 'string', 'max:255'],
            'propertyDetails.hours' => [Rule::requiredIf($isEventAssistance), Rule::prohibitedIf(! $isEventAssistance), 'numeric', 'min:1', 'max:24'],
            'propertyDetails.specialRequirement' => ['nullable', 'string', 'max:255'],
            'propertyDetails.notes' => ['nullable', 'string', 'max:2000'],
            'cleaning_services' => ['sometimes', 'nullable', 'array'],
            'cleaning_services.*' => ['string', 'max:255'],
            'serviceIds' => ['prohibited'],
            'serviceIds.*' => ['prohibited'],
            'scheduledDate' => ['required', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'neighborhoodId' => ['sometimes', 'nullable', 'integer', Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true)],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'numberOfWorkers' => ['nullable', 'integer', 'min:1', 'max:20'],
            ...$this->workerRoomAssignmentRules(),
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateWorkerRoomAssignments($validator);
        });
    }

    private function isEventAssistanceRequested(): bool
    {
        return mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }

    private function availableVenueTypes(): array
    {
        return array_values(array_filter(
            UserCleaningOrderEstimationService::PROPERTY_TYPES,
            static fn (string $type): bool => $type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
        ));
    }
}
