<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Services\CleaningPreferredWorkerRejectionDecisionService;

final class UserCleaningPreferredWorkerRejectionDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => [
                'required',
                'string',
                Rule::in([
                    CleaningPreferredWorkerRejectionDecisionService::DECISION_CONVERT_TO_OPEN,
                    CleaningPreferredWorkerRejectionDecisionService::DECISION_CANCEL,
                ]),
            ],
        ];
    }
}
