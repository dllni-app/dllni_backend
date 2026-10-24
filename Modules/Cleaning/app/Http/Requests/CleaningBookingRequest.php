<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CleaningBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user instanceof User || ! $user->exists) {
            return false;
        }
        $actor = User::query()->whereKey($user->getKey())->where('is_active', true)->first();

        return $actor instanceof User
            && $actor->hasAnyRole(['admin', 'Super Admin', 'Cleaning Ops Manager']);
    }

    public function rules(): array
    {
        if ($this->isMethod('put') || $this->isMethod('patch')) {
            return [
                'propertyDetails' => ['sometimes', 'array'],
                'propertyDetails.kitchens' => ['nullable', 'integer', 'min:0', 'max:20'],
                'propertyDetails.cleaning_mode' => ['nullable', 'string', 'in:regular,deep'],
                'termsAccepted' => ['sometimes', 'boolean'],
                'customerId' => ['prohibited'],
                'workerId' => ['prohibited'],
                'preferredWorkerId' => ['prohibited'],
                'numberOfWorkers' => ['prohibited'],
                'assignmentMode' => ['prohibited'],
                'genderPreference' => ['prohibited'],
                'cancellationPolicyId' => ['prohibited'],
                'billingPolicyId' => ['prohibited'],
                'bookingNumber' => ['prohibited'],
                'status' => ['prohibited'],
                'propertyType' => ['prohibited'],
                'estimatedSqm' => ['prohibited'],
                'estimatedHours' => ['prohibited'],
                'scheduledDate' => ['prohibited'],
                'scheduledTime' => ['prohibited'],
                'totalHours' => ['prohibited'],
                'basePrice' => ['prohibited'],
                'addonsTotal' => ['prohibited'],
                'travelFee' => ['prohibited'],
                'travelDistanceKm' => ['prohibited'],
                'adminMarginAmount' => ['prohibited'],
                'isPricingFinal' => ['prohibited'],
                'cancellationFee' => ['prohibited'],
                'totalPrice' => ['prohibited'],
                'workStartedAt' => ['prohibited'],
                'workFinishedAt' => ['prohibited'],
                'startedTravelAt' => ['prohibited'],
                'customerConfirmedAt' => ['prohibited'],
                'cancelledAt' => ['prohibited'],
                'cancellationReason' => ['prohibited'],
            ];
        }

        return [
            'customerId' => 'required|exists:users,id',
            'workerId' => 'prohibited',
            'preferredWorkerId' => 'nullable|exists:workers,id',
            'numberOfWorkers' => 'nullable|integer|in:1',
            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'genderPreference' => 'nullable|string|in:any,male,female',
            'cancellationPolicyId' => 'nullable|exists:cancellation_policies,id',
            'billingPolicyId' => 'nullable|exists:cleaning_billing_policies,id',
            'bookingNumber' => 'nullable|string|max:255|unique:cleaning_bookings,booking_number,'.$this->route('cleaning_booking')?->id,
            'status' => 'prohibited',
            'propertyType' => 'nullable|string|max:255',
            'propertyDetails' => 'nullable|array',
            'propertyDetails.kitchens' => 'nullable|integer|min:0|max:20',
            'propertyDetails.cleaning_mode' => 'nullable|string|in:regular,deep',
            'estimatedSqm' => 'nullable|numeric',
            'estimatedHours' => 'nullable|numeric',
            'scheduledDate' => 'nullable|date',
            'scheduledTime' => 'nullable|string|max:255',
            'totalHours' => 'nullable|numeric',
            'basePrice' => 'prohibited',
            'addonsTotal' => 'prohibited',
            'travelFee' => 'prohibited',
            'travelDistanceKm' => 'prohibited',
            'adminMarginAmount' => 'prohibited',
            'isPricingFinal' => 'prohibited',
            'cancellationFee' => 'prohibited',
            'totalPrice' => 'prohibited',
            'termsAccepted' => 'nullable|boolean',
            'workStartedAt' => 'prohibited',
            'workFinishedAt' => 'prohibited',
            'startedTravelAt' => 'prohibited',
            'customerConfirmedAt' => 'prohibited',
            'cancelledAt' => 'prohibited',
            'cancellationReason' => 'prohibited',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $propertyDetails = $this->input('propertyDetails');
            if (is_array($propertyDetails)) {
                if ($this->isMethod('put') || $this->isMethod('patch')) {
                    $unexpected = array_diff(array_keys($propertyDetails), ['kitchens', 'cleaning_mode']);
                    if ($unexpected !== []) {
                        $validator->errors()->add(
                            'propertyDetails',
                            'Generic booking updates only allow kitchens and cleaning_mode metadata.',
                        );
                    }
                } elseif ($this->containsReservedPropertyKey($propertyDetails)) {
                    $validator->errors()->add(
                        'propertyDetails',
                        'Reserved booking contract metadata cannot be supplied through the generic booking endpoint.',
                    );
                }
            }

            if ($this->isMethod('put') || $this->isMethod('patch')) {
                return;
            }

            $assignmentMode = $this->normalizedAssignmentMode();
            $preferredWorkerId = $this->input('preferredWorkerId');
            $numberOfWorkers = $this->input('numberOfWorkers');

            if ($assignmentMode === 'preferred_worker') {
                if (! is_numeric($preferredWorkerId) || (int) $preferredWorkerId <= 0) {
                    $validator->errors()->add('preferredWorkerId', 'The preferred worker is required for preferred worker mode.');
                }

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

    /** @param array<mixed> $value */
    private function containsReservedPropertyKey(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && str_starts_with($key, '_')) {
                return true;
            }
            if (is_array($item) && $this->containsReservedPropertyKey($item)) {
                return true;
            }
        }

        return false;
    }
}
