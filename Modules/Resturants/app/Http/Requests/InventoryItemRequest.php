<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:50',
            'quantity' => 'required|numeric|min:0',
            'minimumLimit' => 'required|numeric|min:0',
            'unitCost' => 'nullable|numeric|min:0',
            'productIds' => 'nullable|array',
            'productIds.*' => 'exists:products,id',
        ];
    }
}
