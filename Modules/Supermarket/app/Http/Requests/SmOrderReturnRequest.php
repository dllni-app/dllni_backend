<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:sm_order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Return items are required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required for return.',
            'items.*.order_item_id.required' => 'Order item ID is required.',
            'items.*.order_item_id.exists' => 'Order item does not exist.',
            'items.*.quantity.required' => 'Return quantity is required.',
            'items.*.quantity.integer' => 'Quantity must be an integer.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'reason.required' => 'Return reason is required.',
            'reason.min' => 'Reason must be at least 5 characters.',
            'reason.max' => 'Reason cannot exceed 500 characters.',
        ];
    }
}
