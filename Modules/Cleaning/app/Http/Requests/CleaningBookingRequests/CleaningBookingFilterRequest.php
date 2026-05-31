<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\CleaningBookingRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $statuses = array_map(
            static fn (CleaningBookingStatus $status): string => $status->value,
            CleaningBookingStatus::cases()
        );

        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.status' => [
                'sometimes',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail) use ($statuses): void {
                    $statusParts = array_values(array_filter(
                        array_map('trim', explode(',', (string) $value)),
                        static fn (string $status): bool => $status !== ''
                    ));

                    if ($statusParts === []) {
                        $fail("The {$attribute} field is invalid.");

                        return;
                    }

                    foreach ($statusParts as $status) {
                        if (! in_array($status, $statuses, true)) {
                            $fail("The {$attribute} field is invalid.");

                            return;
                        }
                    }
                },
            ],
            'filter.scheduledDateFrom' => 'sometimes|date',
            'filter.scheduledDateTo' => 'sometimes|date|after_or_equal:filter.scheduledDateFrom',
            'filter.scheduledDate' => 'sometimes|date',
            'filter.customerId' => 'sometimes|exists:users,id',
            'filter.workerId' => 'sometimes|exists:workers,id',
            'filter.propertyType' => ['sometimes', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'filter.forCurrentWorker' => 'sometimes|boolean',
            'filter.hasDispute' => 'sometimes|boolean',
            'sort' => 'sometimes|string|in:scheduledDate,-scheduledDate,createdAt,-createdAt,status,-status,totalPrice,-totalPrice',
        ];
    }
}
