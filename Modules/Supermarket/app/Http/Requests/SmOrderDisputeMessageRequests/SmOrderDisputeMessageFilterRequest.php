<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOrderDisputeMessageRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderDisputeMessageFilterRequest extends FormRequest
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
            'filter.disputeId' => 'sometimes|integer|exists:sm_order_disputes,id',
            'filter.userId' => 'sometimes|integer|exists:users,id',
            'filter.isInternal' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:createdAt,-createdAt',
        ];
    }
}
