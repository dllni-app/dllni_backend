<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MerchantPreparationEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preparationTimeMinutes' => ['present', 'nullable', 'integer', 'min:1', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'preparationTimeMinutes.present' => 'The preparation time field is required and may be null.',
        ];
    }
}
