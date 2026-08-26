<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DiscoverRestaurantsRequest extends FormRequest
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['sometimes', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'filter.openNow' => ['sometimes', 'boolean'],
            'filter.hasOffers' => ['sometimes', 'boolean'],
            'filter.preparationTimeMin' => ['sometimes', 'integer', 'min:1'],
            'filter.preparationTimeMax' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', 'string', 'in:rating,nearest,fastest'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $min = $this->input('filter.preparationTimeMin');
            $max = $this->input('filter.preparationTimeMax');

            if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                $validator->errors()->add(
                    'filter.preparationTimeMax',
                    'The maximum preparation time must be greater than or equal to the minimum preparation time.'
                );
            }
        });
    }
}
