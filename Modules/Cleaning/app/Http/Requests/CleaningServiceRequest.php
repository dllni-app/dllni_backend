<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cleaningService = $this->route('cleaning_service');

        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:cleaning_services,slug,'.($cleaningService?->id ?? 'NULL'),
            'category' => 'required|string|in:cleaning,event_assistance,other',
            'description' => 'nullable|string',
            'isActive' => 'nullable|boolean',
        ];
    }
}
