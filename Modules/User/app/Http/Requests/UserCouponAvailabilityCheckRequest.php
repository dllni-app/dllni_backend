<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UserCouponAvailabilityCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('section') === 'restaurants') {
            $this->merge(['section' => 'restaurant']);
        }

        if (is_string($this->input('couponCode'))) {
            $this->merge(['couponCode' => mb_strtoupper(trim((string) $this->input('couponCode')))]);
        }
    }

    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(['restaurant', 'supermarket', 'cleaning'])],
            'couponCode' => ['required', 'string', 'max:50'],
            'cartId' => ['nullable', 'integer', 'min:1'],
            'propertyType' => ['nullable', 'string', 'max:100'],
            'propertyDetails' => ['nullable', 'array'],
            'addressLatitude' => ['nullable', 'numeric', 'between:-90,90'],
            'addressLongitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferredWorkerId' => ['nullable', 'integer', Rule::exists('workers', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('section') !== 'cleaning') {
                return;
            }

            if (! $this->filled('propertyType')) {
                $validator->errors()->add('propertyType', 'Property type is required for cleaning coupons.');
            }

            if (! is_array($this->input('propertyDetails'))) {
                $validator->errors()->add('propertyDetails', 'Property details are required for cleaning coupons.');
            }
        });
    }
}
