<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => 'required|exists:users,id',
            'firstName' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'averageRating' => 'nullable|numeric',
            'totalCompletedJobs' => 'nullable|integer|min:0',
            'trustScore' => 'nullable|integer|min:0|max:100',
            'acceptanceRate' => 'nullable|numeric',
            'cancellationRate' => 'nullable|numeric',
            'openDisputesCount' => 'nullable|integer|min:0',
            'isActive' => 'nullable|boolean',
            'isSuspended' => 'nullable|boolean',
            'suspendedUntil' => 'nullable|date',
            'homeAddress' => 'nullable|string|max:255',
            'homeLatitude' => 'nullable|numeric',
            'homeLongitude' => 'nullable|numeric',
            'defaultWorkingHours' => 'nullable|array',
        ];
    }
}
