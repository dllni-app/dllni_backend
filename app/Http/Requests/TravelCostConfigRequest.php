<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TravelCostConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'maxKm' => 'required|numeric|min:0',
            'costPerKm' => 'nullable|numeric|min:0',
            'fixedFee' => 'nullable|numeric|min:0',
            'isActive' => 'nullable|boolean',
        ];
    }
}
