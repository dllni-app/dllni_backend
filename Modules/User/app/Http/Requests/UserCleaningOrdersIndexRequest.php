<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class UserCleaningOrdersIndexRequest extends FormRequest
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
        $statuses = array_map(
            static fn (CleaningBookingStatus $status): string => $status->value,
            CleaningBookingStatus::cases()
        );

        return [
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', 'string', Rule::in($statuses)],
            'filter.scheduledDate' => ['sometimes', 'date'],
            'filter.propertyType' => ['sometimes', 'string', Rule::in(UserCleaningOrderEstimationService::PROPERTY_TYPES)],
            'sort' => ['sometimes', 'string', 'in:scheduledDate,-scheduledDate,createdAt,-createdAt,status,-status,totalPrice,-totalPrice'],
        ];
    }
}
