<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bookingId' => 'required|integer',
            'bookingType' => 'required|string|in:cleaning_booking,event_booking',
            'ticketNumber' => 'nullable|string|max:255|unique:disputes,ticket_number,'.$this->route('dispute')?->id,
            'category' => 'nullable|string|in:poor_quality,property_damage,unprofessional,billing_issue,other',
            'status' => 'nullable|string|in:open,under_review,resolved,closed',
            'resolution' => 'nullable|string|in:full_refund,partial_refund,worker_penalty,dismissed',
        ];
    }
}
