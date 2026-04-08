<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserSupermarketOrderStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchantId' => ['required', 'integer', 'exists:sm_stores,id'],
            'fulfillmentType' => ['required', 'string', Rule::in(['pickup'])],
            'receiveMode' => ['required', 'string', Rule::in(['immediate', 'scheduled'])],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
            'addressId' => ['sometimes', 'nullable', 'integer', 'exists:user_addresses,id'],
            'couponCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
