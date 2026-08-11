<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningNeighborhoodIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'activeOnly' => ['sometimes', 'boolean'],
        ];
    }

    public function activeOnly(): bool
    {
        if (! $this->has('activeOnly')) {
            return true;
        }

        return filter_var($this->input('activeOnly'), FILTER_VALIDATE_BOOLEAN);
    }
}
