<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserAddressStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:255'],
            'neighborhoodId' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true),
            ],
            'neighborhood' => ['required_without:neighborhoodId', 'nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:50'],
            'directions' => ['required', 'string', 'max:2000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'city.required' => 'يرجى إدخال المدينة.',
            'neighborhood.required_without' => 'يرجى اختيار الحي.',
            'directions.required' => 'يرجى إدخال تفاصيل العنوان الأخرى.',
        ];
    }
}
