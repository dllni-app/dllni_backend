<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\GenderPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserCleaningPreviousWorkersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'genderPreference' => [
                'sometimes',
                'nullable',
                'string',
                Rule::enum(GenderPreference::class),
            ],
            'scheduledDate' => ['sometimes', 'nullable', 'date'],
            'scheduledTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'durationHours' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:168'],
        ];
    }
}
