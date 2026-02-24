<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrderAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preparationTimeMinutes' => 'required|integer|min:1|max:120',
            'assignedEmployeeId' => 'nullable|exists:users,id',
            'kitchenNotes' => 'nullable|string|max:1000',
        ];
    }
}
