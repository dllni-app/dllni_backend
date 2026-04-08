<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserOrderSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(['restaurant', 'supermarket'])],
            'merchantId' => ['required', 'integer', 'min:1'],
            'fulfillmentType' => ['sometimes', 'nullable', 'string', 'max:50'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
