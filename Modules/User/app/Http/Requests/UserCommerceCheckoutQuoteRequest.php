<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserCommerceCheckoutQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section' => ['required', Rule::in(['restaurant', 'supermarket'])],
            'cartId' => ['required', 'integer', 'min:1'],
            'fulfillmentType' => ['required', Rule::in(['delivery', 'pickup'])],
            'receiveMode' => ['required', Rule::in(['immediate', 'scheduled'])],
            'scheduledAt' => ['nullable', 'date', 'after:now'],
            'addressId' => ['sometimes', 'nullable', 'integer', 'exists:user_addresses,id'],
            'couponCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
