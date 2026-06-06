<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
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
            'propertyDetails' => ['sometimes', 'array:address,location_name,bedrooms,rooms,bathrooms,kitchens,balconies,living_room_size,cleaning_mode,room_size_breakdown,eventType,guestCount,venueType,specialRequirement,notes'],
            'propertyDetails.address' => ['sometimes', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.balconies' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.cleaning_mode' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::CLEANING_MODES)],
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
            'assignmentMode' => ['sometimes', 'nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignmentMode = $this->normalizedAssignmentMode();
            $preferredWorkerId = $this->input('preferredWorkerId');
            $numberOfWorkers = $this->input('numberOfWorkers');

            if ($assignmentMode === 'preferred_worker' && is_numeric($preferredWorkerId) && (int) $preferredWorkerId > 0) {
                if ($numberOfWorkers !== null && (int) $numberOfWorkers !== 1) {
                    $validator->errors()->add('numberOfWorkers', 'Preferred worker mode only allows one worker.');
                }
            }

            if ($assignmentMode === 'open_count' && $preferredWorkerId !== null) {
                $validator->errors()->add('preferredWorkerId', 'Preferred worker cannot be used with open count mode.');
            }

            if ($assignmentMode === null && $preferredWorkerId !== null && $numberOfWorkers !== null && (int) $numberOfWorkers !== 1) {
                $validator->errors()->add('numberOfWorkers', 'Legacy preferred worker requests only support one worker.');
            }
        });
    }

    private function normalizedAssignmentMode(): ?string
    {
        $assignmentMode = $this->input('assignmentMode');

        if (! is_string($assignmentMode) || mb_trim($assignmentMode) === '') {
            return null;
        }

        return mb_strtolower(mb_trim($assignmentMode));
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
