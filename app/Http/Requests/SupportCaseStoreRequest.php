<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DisputeCategory;
use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupportCaseStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(SupportCaseKind::class)],
            'bookingId' => ['required', 'integer', 'exists:cleaning_bookings,id'],
            'bookingType' => ['nullable', 'string', Rule::in(['cleaning_booking'])],
            'category' => [
                Rule::requiredIf(fn (): bool => $this->input('kind') === SupportCaseKind::Complaint->value),
                'nullable',
                Rule::enum(DisputeCategory::class),
            ],
            'emergencyType' => [
                Rule::requiredIf(fn (): bool => $this->input('kind') === SupportCaseKind::Emergency->value),
                'nullable',
                Rule::enum(EmergencyType::class),
            ],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'clientRequestId' => ['nullable', 'string', 'max:100'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'bookingType' => $this->input('bookingType', 'cleaning_booking'),
        ];

        foreach (['description', 'clientRequestId'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $merge[$key] = trim((string) $this->input($key));
            }
        }

        $this->merge($merge);
    }
}
