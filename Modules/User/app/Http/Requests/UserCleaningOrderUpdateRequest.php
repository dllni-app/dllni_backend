<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $eventPayloadRequested = $this->shouldValidateEventPayload();
        $isEventAssistance = $this->isEventAssistanceContext();
        $requiresFemaleWorkerSafetyConfirmation = $this->requiresFemaleWorkerSafetyConfirmation();
        $today = now(config('app.timezone'))->toDateString();

        return [
            'propertyType' => ['sometimes', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'propertyDetails' => ['sometimes', 'array:address,location_name,bedrooms,rooms,bathrooms,toilets,kitchens,balconies,sheds,living_room_size,cleaning_mode,room_size_breakdown,eventType,guestCount,venueType,customService,hours,specialRequirement,notes'],
            'propertyDetails.address' => ['sometimes', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.toilets' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.balconies' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.sheds' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::LIVING_ROOM_SIZES)],
            'propertyDetails.cleaning_mode' => ['nullable', 'string', Rule::in(UserCleaningOrderEstimationService::CLEANING_MODES)],
            'propertyDetails.room_size_breakdown' => ['nullable', 'array:bedroom,bathroom,toilet,kitchen,living_room,balcony,corridor,shed'],
            'propertyDetails.room_size_breakdown.bedroom' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.bathroom' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.toilet' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.kitchen' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.living_room' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.balcony' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.corridor' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.shed' => ['sometimes', 'array:small,medium,large'],
            'propertyDetails.room_size_breakdown.*.small' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.*.medium' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.room_size_breakdown.*.large' => ['sometimes', 'integer', 'min:0'],
            'propertyDetails.eventType' => [Rule::requiredIf($eventPayloadRequested), 'string', Rule::in(UserCleaningOrderEstimationService::EVENT_TYPES)],
            'propertyDetails.guestCount' => [Rule::requiredIf($eventPayloadRequested), 'integer', 'min:1', 'max:5000'],
            'propertyDetails.venueType' => [Rule::requiredIf($eventPayloadRequested), 'string', Rule::in($this->availableVenueTypes())],
            'propertyDetails.customService' => [Rule::requiredIf($eventPayloadRequested), Rule::prohibitedIf(! $isEventAssistance), 'string', 'max:255'],
            'propertyDetails.hours' => [Rule::requiredIf($eventPayloadRequested), Rule::prohibitedIf(! $isEventAssistance), 'numeric', 'min:1', 'max:24'],
            'propertyDetails.specialRequirement' => ['nullable', 'string', 'max:255'],
            'propertyDetails.notes' => ['nullable', 'string', 'max:2000'],
            'cleaning_services' => ['sometimes', 'nullable', 'array'],
            'cleaning_services.*' => ['string', 'max:255'],
            'serviceIds' => ['prohibited'],
            'serviceIds.*' => ['prohibited'],
            'scheduledDate' => ['sometimes', 'date', 'after_or_equal:'.$today],
            'scheduledTime' => ['sometimes', 'date_format:H:i'],
            'addressLatitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'neighborhoodId' => ['sometimes', 'nullable', 'integer', Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true)],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferredWorkerId' => ['sometimes', 'nullable', 'exists:workers,id'],
            'numberOfWorkers' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'assignmentMode' => ['sometimes', 'nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'genderPreference' => ['sometimes', 'nullable', 'string', Rule::in(['any', 'male', 'female'])],
            'workEnvironmentConfirmation' => [Rule::excludeIf(! $requiresFemaleWorkerSafetyConfirmation), Rule::requiredIf($requiresFemaleWorkerSafetyConfirmation), 'array:beneficiaryPresence,pledgeAccepted,pledgeVersion'],
            'workEnvironmentConfirmation.beneficiaryPresence' => [Rule::excludeIf(! $requiresFemaleWorkerSafetyConfirmation), Rule::requiredIf($requiresFemaleWorkerSafetyConfirmation), 'string', Rule::in([FemaleWorkerSafetyPolicyService::BENEFICIARY_FEMALE_PRESENT, FemaleWorkerSafetyPolicyService::BENEFICIARY_MALE_ALONE])],
            'workEnvironmentConfirmation.pledgeAccepted' => [Rule::excludeIf(! $requiresFemaleWorkerSafetyConfirmation), Rule::requiredIf($requiresFemaleWorkerSafetyConfirmation), 'accepted'],
            'workEnvironmentConfirmation.pledgeVersion' => [Rule::excludeIf(! $requiresFemaleWorkerSafetyConfirmation), Rule::requiredIf($requiresFemaleWorkerSafetyConfirmation), 'string', 'max:100'],
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

            if ($assignmentMode === 'open_count' && $preferredWorkerId !== null) {
                $validator->errors()->add('preferredWorkerId', 'Selected worker is not compatible with open count mode.');
            }

            if ($this->requiresFemaleWorkerSafetyConfirmation()) {
                $policy = app(FemaleWorkerSafetyPolicyService::class);
                $beneficiaryPresence = (string) $this->input('workEnvironmentConfirmation.beneficiaryPresence');
                $pledgeVersion = (string) $this->input('workEnvironmentConfirmation.pledgeVersion');

                if ($beneficiaryPresence === FemaleWorkerSafetyPolicyService::BENEFICIARY_MALE_ALONE) {
                    $validator->errors()->add('workEnvironmentConfirmation.beneficiaryPresence', $policy->blockedMessage());
                }

                if ($pledgeVersion !== '' && $pledgeVersion !== $policy->version()) {
                    $validator->errors()->add('workEnvironmentConfirmation.pledgeVersion', 'Invalid pledge version. Please refresh the confirmation screen and try again.');
                }
            }

            if ($this->has('genderPreference') || $this->has('workEnvironmentConfirmation')) {
                $this->validateGenderPreferenceChangeIsStillSafe($validator);
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

    private function isEventAssistanceContext(): bool
    {
        if (mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return true;
        }

        return $this->shouldValidateEventPayload();
    }

    private function shouldValidateEventPayload(): bool
    {
        if (mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE) {
            return true;
        }

        return $this->has('propertyDetails.eventType')
            || $this->has('propertyDetails.guestCount')
            || $this->has('propertyDetails.venueType')
            || $this->has('propertyDetails.customService')
            || $this->has('propertyDetails.hours');
    }

    private function requiresFemaleWorkerSafetyConfirmation(): bool
    {
        return $this->has('genderPreference')
            && mb_strtolower((string) $this->input('genderPreference')) === 'female';
    }

    private function validateGenderPreferenceChangeIsStillSafe(Validator $validator): void
    {
        $bookingId = $this->route('order');

        if (! is_numeric($bookingId)) {
            return;
        }

        $hasAcceptedAssignments = CleaningBooking::query()
            ->whereKey((int) $bookingId)
            ->whereHas('workerAssignments', static function ($query): void {
                $query->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
            })
            ->exists();

        if (! $hasAcceptedAssignments) {
            return;
        }

        $validator->errors()->add('genderPreference', 'Order gender preference cannot be changed after workers have accepted.');
    }

    private function availableVenueTypes(): array
    {
        return array_values(array_filter(
            UserCleaningOrderEstimationService::PROPERTY_TYPES,
            static fn (string $type): bool => $type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
        ));
    }
}
