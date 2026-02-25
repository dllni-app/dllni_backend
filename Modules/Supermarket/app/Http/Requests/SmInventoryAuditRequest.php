<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmInventoryAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'exists:sm_products,id'],
            'products.*.actual_stock' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'products.required' => 'Products list is required.',
            'products.array' => 'Products must be an array.',
            'products.min' => 'At least one product is required for audit.',
            'products.*.product_id.required' => 'Product ID is required.',
            'products.*.product_id.exists' => 'Product does not exist.',
            'products.*.actual_stock.required' => 'Actual stock is required.',
            'products.*.actual_stock.integer' => 'Actual stock must be an integer.',
            'products.*.actual_stock.min' => 'Actual stock cannot be negative.',
        ];
    }
}
