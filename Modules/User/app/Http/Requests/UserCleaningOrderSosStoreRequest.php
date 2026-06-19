<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use App\Enums\EmergencyType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UserCleaningOrderSosStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'message' => ['required', 'string', 'min:3', 'max:1000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'client_request_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        $aliases = [
            'emergencyType' => 'emergency_type',
            'clientRequestId' => 'client_request_id',
            'lat' => 'latitude',
            'lng' => 'longitude',
        ];

        foreach ($aliases as $source => $target) {
            if ($this->has($source) && ! $this->has($target)) {
                $merge[$target] = $this->input($source);
            }
        }

        if ($this->has('message') && is_string($this->input('message'))) {
            $merge['message'] = trim((string) $this->input('message'));
        }

        if ($this->has('client_request_id') && is_string($this->input('client_request_id'))) {
            $merge['client_request_id'] = trim((string) $this->input('client_request_id'));
        }

        if (array_key_exists('client_request_id', $merge) && is_string($merge['client_request_id'])) {
            $merge['client_request_id'] = trim($merge['client_request_id']);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
