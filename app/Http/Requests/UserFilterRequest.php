<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class UserFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.name' => 'sometimes|string|max:255',
            'filter.email' => 'sometimes|email|max:255',
            'filter.emailVerifiedAt' => 'sometimes|date',
            'filter.password' => 'sometimes|string|max:255',
            'filter.rememberToken' => 'sometimes|string|max:255',
            'filter.createdAfter' => 'sometimes|date',
            'filter.createdBefore' => 'sometimes|date|after_or_equal:filter.createdAfter',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,email,-email,emailVerifiedAt,-emailVerifiedAt,password,-password,rememberToken,-rememberToken',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    public function prepareForValidation(): void
    {
        $this->merge($this->convertKeysToSnakeCase($this->all()));
    }

    /**
     * Convert request keys from camelCase to snake_case recursively.
     */
    public function convertKeysToSnakeCase(array $data): array
    {
        $converted = [];

        foreach ($data as $key => $value) {
            $snakeKey = Str::snake($key);

            if (is_array($value)) {
                $converted[$snakeKey] = $this->convertKeysToSnakeCase($value);
            } else {
                $converted[$snakeKey] = $value;
            }
        }

        return $converted;
    }
}
