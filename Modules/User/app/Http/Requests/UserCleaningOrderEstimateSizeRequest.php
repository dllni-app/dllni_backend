<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimateSizeRequest extends FormRequest
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
            'propertyDetails' => ['required', 'array'],
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
            'serviceIds' => [Rule::requiredIf($isEventAssistance), 'array', 'min:1'],
            'serviceIds.*' => ['integer', 'distinct', 'exists:cleaning_services,id'],
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
