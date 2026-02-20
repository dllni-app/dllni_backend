<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'cancellationPolicyId' => 'nullable|exists:cancellation_policies,id',
            'billingPolicyId' => 'nullable|exists:cleaning_billing_policies,id',
            'bookingNumber' => 'nullable|string|max:255|unique:cleaning_bookings,booking_number,'.$this->route('cleaning_booking')?->id,
            'status' => 'nullable|string|in:pending,confirmed,worker_assigned,worker_on_the_way,worker_arrived,in_progress,completed,cancelled',
            'propertyType' => 'nullable|string|max:255',
            'propertyDetails' => 'nullable|array',
            'estimatedSqm' => 'nullable|numeric',
            'estimatedHours' => 'nullable|numeric',
            'scheduledDate' => 'nullable|date',
            'scheduledTime' => 'nullable|string|max:255',
            'totalHours' => 'nullable|numeric',
            'basePrice' => 'nullable|numeric',
            'addonsTotal' => 'nullable|numeric',
            'travelFee' => 'nullable|numeric',
            'cancellationFee' => 'nullable|numeric',
            'totalPrice' => 'nullable|numeric',
            'termsAccepted' => 'nullable|boolean',
            'workStartedAt' => 'nullable|date',
            'workFinishedAt' => 'nullable|date',
            'customerConfirmedAt' => 'nullable|date',
            'cancelledAt' => 'nullable|date',
        ];
    }
}
