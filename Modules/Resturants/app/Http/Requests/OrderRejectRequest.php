<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Resturants\Enums\OrderRejectionReason;

final class OrderRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|in:'.implode(',', array_column(OrderRejectionReason::cases(), 'value')),
            'customerMessage' => 'nullable|string|max:150',
        ];
    }
}
