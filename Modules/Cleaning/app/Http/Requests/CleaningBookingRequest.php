<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CleaningBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerId' => 'required|exists:users,id',
            'workerId' => 'nullable|exists:workers,id',
            'preferredWorkerId' => 'nullable|exists:workers,id',
            'numberOfWorkers' => 'nullable|integer|min:1|max:20',
            'assignmentMode' => ['nullable', 'string', Rule::in(['preferred_worker', 'open_count'])],
            'genderPreference' => 'nullable|string|in:any,male,female',
            'cancellationPolicyId' => 'nullable|exists:cancellation_policies,id',
            'billingPolicyId' => 'nullable|exists:cleaning_billing_policies,id',
            'bookingNumber' => 'nullable|string|max:255|unique:cleaning_bookings,booking_number,'.$this->route('cleaning_booking')?->id,
            'status' => 'nullable|string|in:pending,worker_assigned,in_progress,completed,cancelled',
            'propertyType' => 'nullable|string|max:255',
            'propertyDetails' => 'nullable|array',
            'propertyDetails.kitchens' => 'nullable|integer|min:0|max:20',
            'propertyDetails.cleaning_mode' => 'nullable|string|in:regular,deep',
            'estimatedSqm' => 'nullable|numeric',
            'estimatedHours' => 'nullable|numeric',
            'scheduledDate' => 'nullable|date',
            'scheduledTime' => 'nullable|string|max:255',
            'totalHours' => 'nullable|numeric',
            'basePrice' => 'nullable|numeric',
            'addonsTotal' => 'nullable|numeric',
            'travelFee' => 'nullable|numeric',
            'travelDistanceKm' => 'nullable|numeric|min:0',
            'adminMarginAmount' => 'nullable|numeric|min:0',
            'isPricingFinal' => 'nullable|boolean',
            'cancellationFee' => 'nullable|numeric',
            'totalPrice' => 'nullable|numeric',
            'termsAccepted' => 'nullable|boolean',
            'workStartedAt' => 'nullable|date',
            'workFinishedAt' => 'nullable|date',
            'startedTravelAt' => 'nullable|date',
            'customerConfirmedAt' => 'nullable|date',
            'cancelledAt' => 'nullable|date',
            'cancellationReason' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
}
