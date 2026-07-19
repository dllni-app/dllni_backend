<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Models\Worker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;

final class CleaningBookingAcceptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $worker = $this->user()?->worker;
                $booking = $this->route('cleaning_booking');

                if (! $worker instanceof Worker || ! $booking instanceof CleaningBooking) {
                    return;
                }

                if (app(WorkerBookingScheduleConflictService::class)->hasConflict($worker, $booking)) {
                    $validator->errors()->add(
                        'schedule',
                        'This booking overlaps another confirmed booking in your schedule.'
                    );
                }
            },
        ];
    }
}
