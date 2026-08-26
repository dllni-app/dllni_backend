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

                $conflicts = app(WorkerBookingScheduleConflictService::class)->conflictsForBooking($worker, $booking);
                if ($conflicts === []) {
                    return;
                }

                $validator->errors()->add(
                    'schedule',
                    $booking->isMultiDayEventAssistance()
                        ? 'Worker is not available for all event days.'
                        : 'This booking overlaps another confirmed booking in your schedule.'
                );

                foreach ($conflicts as $conflict) {
                    $validator->errors()->add(
                        'scheduleConflicts',
                        sprintf('%s %s-%s', $conflict['date'], $conflict['start'], $conflict['end'])
                    );
                }
            },
        ];
    }
}
