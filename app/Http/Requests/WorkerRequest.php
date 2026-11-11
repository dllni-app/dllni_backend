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
            'gender' => 'nullable|string|in:male,female',
            'birthday' => 'nullable|date',
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
            'avatar' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048',
        ];
    }
}
