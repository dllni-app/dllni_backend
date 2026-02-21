<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOrderDisputeRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderDisputeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'filter.orderId' => 'sometimes|integer|exists:sm_orders,id',
            'filter.openedByUserId' => 'sometimes|integer|exists:users,id',
            'filter.resolvedByUserId' => 'sometimes|integer|exists:users,id',
            'filter.status' => 'sometimes|string|in:open,under_review,resolved,closed',
            'filter.ticketNumber' => 'sometimes|string|max:255',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:ticketNumber,-ticketNumber,status,-status,createdAt,-createdAt',
        ];
    }
}
