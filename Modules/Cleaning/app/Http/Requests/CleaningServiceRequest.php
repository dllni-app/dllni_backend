<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Enums\ServiceCategory;

final class CleaningServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'category' => ['required', 'string', Rule::in([
                ServiceCategory::Cleaning->value,
                ServiceCategory::EventAssistance->value,
            ])],
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'isActive' => 'nullable|boolean',
        ];
    }
}
