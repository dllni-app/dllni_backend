<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningTimeWarningAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'additionalMinutes' => 'nullable|integer|min:1|max:480',
        ];
    }
}
