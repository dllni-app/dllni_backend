<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\User\Models\UserAddress;

final class UserAddressPatchRequest extends FormRequest
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
            'label' => ['sometimes', 'string', 'max:100'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:255'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'building' => ['sometimes', 'nullable', 'string', 'max:255'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:50'],
            'directions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'isDefault' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $validated = $this->validated();

            if ($validated === []) {
                $validator->errors()->add('payload', 'At least one field must be provided.');

                return;
            }

            $address = $this->route('userAddress');
            if (! $address instanceof UserAddress) {
                return;
            }

            $future = [
                'mobile' => array_key_exists('mobile', $validated) ? $validated['mobile'] : $address->mobile,
                'city' => array_key_exists('city', $validated) ? $validated['city'] : $address->city,
                'neighborhood' => array_key_exists('neighborhood', $validated) ? $validated['neighborhood'] : $address->neighborhood,
                'street' => array_key_exists('street', $validated) ? $validated['street'] : $address->street,
                'building' => array_key_exists('building', $validated) ? $validated['building'] : $address->building,
                'floor' => array_key_exists('floor', $validated) ? $validated['floor'] : $address->floor,
                'directions' => array_key_exists('directions', $validated) ? $validated['directions'] : $address->directions,
            ];

            $hasDetail = collect($future)->filter(fn ($v): bool => $v !== null && $v !== '')->isNotEmpty();
            if (! $hasDetail) {
                $validator->errors()->add('city', 'Provide at least one address detail (mobile, city, neighborhood, street, building, floor, or directions).');
            }
        });
    }
}
