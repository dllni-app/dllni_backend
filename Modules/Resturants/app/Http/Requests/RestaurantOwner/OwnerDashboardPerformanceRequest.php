<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerDashboardPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'range' => 'sometimes|string|in:today,week,month,year,custom',
            'from' => 'required_if:range,custom|date',
            'to' => 'required_if:range,custom|date|after_or_equal:from',
        ];
    }
}
