<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\GenderPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\User\Http\Requests\Concerns\ValidatesEventAssistanceSchedule;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningPreviousWorkersRequest extends FormRequest
{
    use ValidatesEventAssistanceSchedule;

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $isEventAssistance = mb_strtolower((string) $this->input('propertyType')) === UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE;

        return [
            'propertyType' => ['sometimes', 'nullable', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'genderPreference' => [
                'sometimes',
                'nullable',
                'string',
                Rule::enum(GenderPreference::class),
            ],
            'scheduledDate' => ['sometimes', 'nullable', 'date'],
            'scheduledTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'durationHours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:168'],
            ...$this->eventAssistanceScheduleRules($isEventAssistance),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateEventAssistanceSchedule($validator);
        });
    }
}
