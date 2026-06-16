<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
        ];
    }
}
