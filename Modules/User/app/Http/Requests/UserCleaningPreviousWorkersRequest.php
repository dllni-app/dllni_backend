<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\GenderPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningPreviousWorkersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'propertyType' => [
                'sometimes',
                'string',
                'max:255',
                'in:'.implode(',', UserCleaningOrderEstimationService::PROPERTY_TYPES),
            ],
            'genderPreference' => [
                'sometimes',
                'nullable',
                'string',
                Rule::enum(GenderPreference::class),
            ],
            'scheduledDate' => ['sometimes', 'nullable', 'date'],
            'scheduledTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'durationHours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:168'],
            'neighborhoodId' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true),
            ],
        ];
    }
}
