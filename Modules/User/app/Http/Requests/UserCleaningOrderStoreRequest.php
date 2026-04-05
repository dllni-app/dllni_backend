<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderStoreRequest extends FormRequest
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
        return [
            'propertyType' => ['required', 'string', 'max:255'],
            'propertyDetails' => ['required', 'array'],
            'propertyDetails.address' => ['required', 'string', 'max:500'],
            'propertyDetails.location_name' => ['nullable', 'string', 'max:255'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:1', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'estimatedSqm' => ['nullable', 'numeric', 'min:1'],
            'totalHours' => ['required', 'numeric', 'min:1', 'max:24'],
            'scheduledDate' => ['required', 'date', 'after_or_equal:today'],
            'scheduledTime' => ['required', 'date_format:H:i'],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
            'basePrice' => ['nullable', 'numeric', 'min:0'],
            'travelFee' => ['nullable', 'numeric', 'min:0'],
            'addonsTotal' => ['nullable', 'numeric', 'min:0'],
            'totalPrice' => ['nullable', 'numeric', 'min:0'],
            'cancellationPolicyId' => ['nullable', 'exists:cancellation_policies,id'],
            'billingPolicyId' => ['nullable', 'exists:cleaning_billing_policies,id'],
            'termsAccepted' => ['required', 'accepted'],
        ];
    }
}
