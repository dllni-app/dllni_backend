<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmOrderDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $disputeId = $this->route('sm_order_dispute');

        return [
            'orderId' => 'sometimes|required|integer|exists:sm_orders,id',
            'openedByUserId' => 'sometimes|required|integer|exists:users,id',
            'ticketNumber' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sm_order_disputes', 'ticket_number')->ignore($disputeId),
            ],
            'status' => 'sometimes|required|string|in:open,under_review,resolved,closed',
            'reason' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'resolvedAt' => 'nullable|date',
            'resolvedByUserId' => 'nullable|integer|exists:users,id',
            'resolutionNotes' => 'nullable|string',
        ];
    }
}
