<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesWorkerRoomAssignments;
use Modules\User\Models\UserAddress;
use Modules\User\Services\FemaleWorkerSafetyPolicyService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderStoreRequest extends FormRequest
{
    use ValidatesWorkerRoomAssignments;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $assignmentMode = $this->input('assignmentMode');
        $isPreferredWorkerMode = is_string($assignmentMode)
            && mb_strtolower(mb_trim($assignmentMode)) === 'preferred_worker';

        $preferredWorkerIds = $this->normalizePreferredWorkerIds(
            $this->input('preferredWorkerIds', $this->input('preferredWorkerId'))
        );

        if ($preferredWorkerIds !== [] || $this->has('preferredWorkerIds')) {
            $merge['preferredWorkerIds'] = $preferredWorkerIds;
            $merge['preferredWorkerId'] = $preferredWorkerIds[0] ?? null;

            if ($isPreferredWorkerMode && count($preferredWorkerIds) === 1) {
                $merge['numberOfWorkers'] = 1;
            } elseif (count($preferredWorkerIds) > 1) {
                if (! $this->filled('numberOfWorkers')) {
                    $merge['numberOfWorkers'] = count($preferredWorkerIds);
                }

                if (! $this->filled('assignmentMode')) {
                    $merge['assignmentMode'] = 'open_count';
                }
            }
        }

        $addressId = $this->input('addressId');
        if (is_numeric($addressId) && $this->user() !== null) {
            $address = UserAddress::query()
                ->whereKey((int) $addressId)
                ->where('user_id', (int) $this->user()->id)
                ->first();

            if ($address instanceof UserAddress) {
                $merge['addressLatitude'] = $address->latitude !== null ? (float) $address->latitude : null;
                $merge['addressLongitude'] = $address->longitude !== null ? (float) $address->longitude : null;

                if (! $this->filled('neighborhoodId') && $address->neighborhood_id !== null) {
                    $merge['neighborhoodId'] = (int) $address->neighborhood_id;
                }

                if (! $this->filled('neighborhood') && $address->neighborhood !== null) {
                    $merge['neighborhood'] = $address->neighborhood;
                }

                $propertyDetails = $this->input('propertyDetails');
                if (is_array($propertyDetails)) {
                    if (! array_key_exists('address', $propertyDetails) || mb_trim((string) ($propertyDetails['address'] ?? '')) === '') {
                        $propertyDetails['address'] = $this->formatUserAddress($address);
                    }

                    if (! array_key_exists('location_name', $propertyDetails) && $address->label !== null) {
                        $propertyDetails['location_name'] = $address->label;
                    }

                    $merge['propertyDetails'] = $propertyDetails;
                }
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $isEventAssistance = $this->isEventAssistanceRequested();
        $requiresFemaleWorkerSafetyConfirmation = $this->requiresFemaleWorkerSafetyConfirmation();
        $today = now(config('app.timezone'))->toDateString();

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
            'scheduledDate' => ['required', 'date', 'after_or_equal:'.$today],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'addressId' => ['nullable', 'integer', Rule::exists('user_addresses', 'id')->where('user_id', (int) ($this->user()?->id ?? 0))],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'neighborhoodId' => ['sometimes', 'nullable', 'integer', Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true)],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preferredWorkerIds' => ['nullable', 'array', 'max:20'],
            'preferredWorkerIds.*' => ['integer', 'distinct', Rule::exists('workers', 'id')],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'numberOfWorkers' => ['nullable', 'integer', 'min:1', 'max:20'],
            ...$this->workerRoomAssignmentRules(),
            'genderPreference' => ['nullable', 'string', Rule::in(['any', 'male', 'female'])],
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
            'cancellationPolicyId' => ['nullable', 'exists:cancellation_policies,id'],
            'billingPolicyId' => ['nullable', 'exists:cleaning_billing_policies,id'],
            'termsAccepted' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('addressId') && (! $this->filled('addressLatitude') || ! $this->filled('addressLongitude'))) {
                $validator->errors()->add('addressId', 'Selected address must include latitude and longitude coordinates.');
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

            $this->validateWorkerRoomAssignments($validator);
        });
    }

    /**
     * @return array<int, int>
     */
    private function normalizePreferredWorkerIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($values as $item) {
            if (! is_numeric($item)) {
                continue;
            }

            $id = (int) $item;
            if ($id <= 0 || in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function formatUserAddress(UserAddress $address): string
    {
        $parts = array_values(array_filter([
            $address->city,
            $address->neighborhood,
            $address->street,
            $address->building !== null ? 'Building '.$address->building : null,
            $address->floor !== null ? 'Floor '.$address->floor : null,
            $address->directions,
        ], static fn (mixed $part): bool => is_string($part) && mb_trim($part) !== ''));

        if ($parts !== []) {
            return mb_substr(implode(' - ', $parts), 0, 500);
        }

        return mb_substr((string) $address->label, 0, 500);
    }

    private function isEventAssistanceRequested(): bool
    {
        return mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;
    }

    private function requiresFemaleWorkerSafetyConfirmation(): bool
    {
        return mb_strtolower((string) $this->input('genderPreference')) === 'female';
    }

    private function availableVenueTypes(): array
    {
        return array_values(array_filter(
            UserCleaningOrderEstimationService::PROPERTY_TYPES,
            static fn (string $type): bool => $type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE
        ));
    }
}
