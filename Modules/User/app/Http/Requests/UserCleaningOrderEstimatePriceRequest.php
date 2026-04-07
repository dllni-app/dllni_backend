<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderEstimatePriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'propertyType' => ['required', 'string', 'max:255'],
            'propertyDetails' => ['required', 'array'],
            'propertyDetails.bedrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.rooms' => ['nullable', 'integer', 'min:0', 'max:30'],
            'propertyDetails.bathrooms' => ['nullable', 'integer', 'min:0', 'max:20'],
            'propertyDetails.living_room_size' => ['nullable', 'string', 'in:small,medium,large,very_large'],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'exists:workers,id'],
        ];
    }
}
