<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserCouponAvailabilityCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section' => ['required', 'string', Rule::in(['restaurants', 'supermarket'])],
            'couponCode' => ['required', 'string', 'max:50'],
        ];
    }
}
