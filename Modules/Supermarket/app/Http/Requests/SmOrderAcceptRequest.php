<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOrderAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'preparationTimeMinutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
        ];
    }
}
