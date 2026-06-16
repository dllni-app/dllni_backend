<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class DriverAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['availabilityStatus' => 'required|string|in:available,busy,offline'];
    }
}
