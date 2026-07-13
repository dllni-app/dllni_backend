<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $isAdmin = $this->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;

        return [
            'bookingId' => ['required', 'integer'],
            'bookingType' => ['required', 'string', Rule::in(['cleaning_booking', 'event_booking'])],
            'ticketNumber' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('disputes', 'ticket_number')->ignore($this->route('dispute')),
            ],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
            'category' => ['required', Rule::enum(DisputeCategory::class)],
            'status' => $isAdmin
                ? ['nullable', Rule::enum(DisputeStatus::class)]
                : ['prohibited'],
            'resolution' => $isAdmin
                ? ['nullable', Rule::enum(DisputeResolution::class)]
                : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('description') && is_string($this->input('description'))) {
            $this->merge(['description' => trim((string) $this->input('description'))]);
        }
    }
}
