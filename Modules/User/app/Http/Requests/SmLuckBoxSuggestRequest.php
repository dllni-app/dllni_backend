<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SmLuckBoxSuggestRequest extends FormRequest
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
            'groupSize' => ['required', 'integer', 'min:1', 'max:50'],
            'budgetPerPerson' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'restrictions' => ['sometimes', 'array', 'max:20'],
            'restrictions.*' => ['string', 'in:vegetarian,gluten_free,nut_free,dairy_free,halal_friendly'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'searchRadiusKm' => ['sometimes', 'numeric', 'min:0.5', 'max:200'],
            'categoryId' => ['sometimes', 'nullable', 'integer', 'exists:sm_categories,id'],
            'storeId' => ['sometimes', 'nullable', 'integer', 'exists:sm_stores,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lat = $this->input('latitude');
            $lng = $this->input('longitude');
            $radius = $this->input('searchRadiusKm');

            if ($radius !== null && $radius !== '' && (! is_numeric($lat) || ! is_numeric($lng))) {
                $validator->errors()->add('searchRadiusKm', 'latitude and longitude are required when searchRadiusKm is set.');
            }
        });
    }
}
