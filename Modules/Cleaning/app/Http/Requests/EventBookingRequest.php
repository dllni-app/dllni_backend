<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EventBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $today = now(config('app.timezone'))->toDateString();

        return [
            'customerId' => 'required|exists:users,id',
            'cancellationPolicyId' => 'nullable|exists:cancellation_policies,id',
            'billingPolicyId' => 'nullable|exists:cleaning_billing_policies,id',
            'bookingNumber' => 'nullable|string|max:255|unique:event_bookings,booking_number,'.$this->route('event_booking')?->id,
            'status' => 'nullable|string|in:pending,confirmed,team_assigned,in_progress,completed,cancelled',
            'eventType' => 'nullable|string|in:family_dinner,birthday,large_gathering,funeral,other',
            'guestCountMin' => 'nullable|integer|min:0',
            'guestCountMax' => 'nullable|integer|min:0',
            'genderPreference' => 'nullable|string|max:255',
            'suggestedTeamSize' => 'nullable|integer|min:0',
            'scheduledDate' => 'nullable|date|after_or_equal:'.$today,
            'scheduledTime' => 'nullable|string|max:255',
            'totalHours' => 'nullable|numeric',
            'basePrice' => 'nullable|numeric',
            'travelFee' => 'nullable|numeric',
            'totalPrice' => 'nullable|numeric',
            'termsAccepted' => 'nullable|boolean',
            'cancelledAt' => 'nullable|date',
        ];
    }
}
