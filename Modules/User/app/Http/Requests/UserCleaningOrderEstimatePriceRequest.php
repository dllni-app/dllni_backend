<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\GenderPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesWorkerRoomAssignments;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceRequest extends FormRequest
{
    use ValidatesWorkerRoomAssignments;

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
            'propertyDetails.toilets' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.balconies' => ['nullable', 'integer', 'min:0', 'max:20'],
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
            'serviceIds' => $isEventAssistance ? ['prohibited'] : ['sometimes', 'array', 'min:1'],
            'serviceIds.*' => $isEventAssistance ? ['prohibited'] : ['integer', 'distinct', 'exists:cleaning_services,id'],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'numberOfWorkers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'genderPreference' => ['nullable', 'string', Rule::in(array_column(GenderPreference::cases(), 'value'))],
            ...$this->workerRoomAssignmentRules(),
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
                    $validator->errors()->add('numberOfWorkers', 'Selected worker mode only allows one worker.');
                }
            }

            if ($assignmentMode === 'open_count' && $preferredWorkerId !== null) {
                $validator->errors()->add('preferredWorkerId', 'Selected worker is not compatible with open count mode.');
            }

            if ($assignmentMode === null && $preferredWorkerId !== null && $numberOfWorkers !== null && (int) $numberOfWorkers !== 1) {
                $validator->errors()->add('numberOfWorkers', 'Legacy selected-worker requests only support one worker.');
            }

            $this->validateWorkerRoomAssignments($validator);
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
