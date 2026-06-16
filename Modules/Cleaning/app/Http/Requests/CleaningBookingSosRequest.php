<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Enums\EmergencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CleaningBookingSosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Rule>>
     */
    public function rules(): array
    {
        return [
            'emergency_type' => ['required', 'string', Rule::in(array_map(
                static fn (EmergencyType $type): string => $type->value,
                EmergencyType::cases(),
            ))],
            'message' => ['required', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('message') && is_string($this->input('message'))) {
            $merge['message'] = trim((string) $this->input('message'));
        }

        if ($this->has('client_request_id') && is_string($this->input('client_request_id'))) {
            $merge['client_request_id'] = trim((string) $this->input('client_request_id'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
