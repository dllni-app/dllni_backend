<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class DriverCallEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:CUSTOMER_CALL_ATTEMPTED,CUSTOMER_CALL_CONNECTED,CUSTOMER_NO_ANSWER',
            'target' => 'nullable|string|max:100',
            'timestamp' => 'nullable|date',
            'note' => 'nullable|string|max:255',
        ];
    }
}

