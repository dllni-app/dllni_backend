<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderDisputeMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disputeId' => 'sometimes|required|integer|exists:sm_order_disputes,id',
            'userId' => 'sometimes|required|integer|exists:users,id',
            'message' => 'sometimes|required|string|max:5000',
            'isInternal' => 'sometimes|boolean',
        ];
    }
}
