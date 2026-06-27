<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\GenderPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesWorkerRoomAssignments;
use Modules\User\Models\UserAddress;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrderEstimatePriceRequest extends FormRequest
{
    use ValidatesWorkerRoomAssignments;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $preferredWorkerIds = $this->normalizePreferredWorkerIds(
            $this->input('preferredWorkerIds', $this->input('preferredWorkerId'))
        );

        if ($preferredWorkerIds !== [] || $this->has('preferredWorkerIds')) {
            $merge['preferredWorkerIds'] = $preferredWorkerIds;
            $merge['preferredWorkerId'] = $preferredWorkerIds[0] ?? null;

            if (count($preferredWorkerIds) > 1) {
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
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
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
            'addressId' => ['nullable', 'integer', Rule::exists('user_addresses', 'id')->where('user_id', (int) ($this->user()?->id ?? 0))],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferredWorkerIds' => ['nullable', 'array', 'max:20'],
            'preferredWorkerIds.*' => ['integer', 'distinct', Rule::exists('workers', 'id')],
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
            if ($this->filled('addressId') && (! $this->filled('addressLatitude') || ! $this->filled('addressLongitude'))) {
                $validator->errors()->add('addressId', 'Selected address must include latitude and longitude coordinates.');
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
