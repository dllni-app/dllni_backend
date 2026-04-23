<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Supermarket\Enums\DayOfWeek;

final class SmStoreHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $days = implode(',', array_column(DayOfWeek::cases(), 'value'));

        return [
            'isTemporarilyClosed' => 'sometimes|boolean',
            'dailyHours' => 'sometimes|array',
            'dailyHours.*.dayOfWeek' => "required_with:dailyHours|string|in:{$days}",
            'dailyHours.*.isEnabled' => 'sometimes|boolean',
            'dailyHours.*.timeSlots' => 'sometimes|array',
            'dailyHours.*.timeSlots.*.startTime' => 'required_with:dailyHours.*.timeSlots|string',
            'dailyHours.*.timeSlots.*.endTime' => 'required_with:dailyHours.*.timeSlots|string',
        ];
    }
}
