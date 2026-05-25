<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceRequest extends FormRequest
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
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.eventType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in(UserCleaningOrderEstimationService::EVENT_TYPES)],
            'propertyDetails.guestCount' => [Rule::requiredIf($isEventAssistance), 'integer', 'min:1', 'max:5000'],
            'propertyDetails.venueType' => [Rule::requiredIf($isEventAssistance), 'string', Rule::in($this->availableVenueTypes())],
            'serviceIds' => [Rule::requiredIf($isEventAssistance), 'array', 'min:1'],
            'serviceIds.*' => ['integer', 'distinct', 'exists:cleaning_services,id'],
            'addressLatitude' => ['nullable', 'required_with:preferredWorkerId', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'required_with:preferredWorkerId', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
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
