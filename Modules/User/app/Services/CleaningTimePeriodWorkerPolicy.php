<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningTimePeriodWorkerPolicy
{
    public function validate(array $input, array $schedules, int $peak): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', [...(array) ($input['preferredWorkerIds'] ?? []), $input['preferredWorkerId'] ?? 0]))));
        $specific = ($input['workerScope'] ?? '') === 'specific' || ($input['assignmentMode'] ?? '') === 'preferred_worker' || $ids !== [];
        if (! $specific) {
            return;
        }
        if (count($ids) > (int) ($input['numberOfWorkers'] ?? $peak)) {
            $this->invalid('قلّل العمال المفضلين أو زد حجم الفريق المطلوب.');
        }
        $workers = Worker::query()->whereIn('id', $ids)->activeAvailable()->get();
        if ($workers->count() !== count($ids)) {
            $this->invalid('أحد العمال المختارين غير متاح حالياً.');
        }
        foreach ($workers as $worker) {
            if (! app(\Modules\Cleaning\Services\DepositService::class)->isWorkerEligibleForDispatch($worker)) {
                $this->invalid('أحد العمال المختارين غير متاح لاستقبال طلبات جديدة.');
            }
        }
        app(UserCleaningOrderService::class)->validateSelectedWorkerCoverage($input);
        $gender = $input['genderPreference'] ?? 'any';
        if ($gender !== 'any' && $workers->contains(static fn (Worker $worker): bool => (string) $worker->gender !== $gender)) {
            $this->invalid('جنس العمال المختارين لا يطابق تفضيل الحجز.');
        }
        $demand = [];
        foreach ($schedules as $schedule) {
            $start = CarbonImmutable::parse($schedule['startAt']);
            $end = CarbonImmutable::parse($schedule['endAt']);
            for ($minute = $start; $minute->lessThan($end); $minute = $minute->addMinute()) {
                $key = $minute->toIso8601String();
                $demand[$key] = ($demand[$key] ?? 0) + $schedule['numberOfWorkers'];
            }
        }
        $busy = [];
        foreach ($workers as $worker) {
            $bookings = CleaningBooking::query()->whereNotIn('status', ['completed', 'cancelled'])->where(static function ($query) use ($worker): void {
                $query->where('worker_id', $worker->id)->orWhereHas('workerAssignments', static fn ($assignment) => $assignment->where('worker_id', $worker->id)->whereIn('status', \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::acceptedValues()));
            })->with(['roomSchedules', 'sessions'])->get();
            foreach ($bookings as $booking) {
                if ($booking->roomSchedules->isNotEmpty()) {
                    foreach ($booking->roomSchedules as $allocation) {
                        $busy[$worker->id][] = [$allocation->starts_at->timestamp, $allocation->ends_at->timestamp];
                    }
                } elseif ($booking->sessions->isNotEmpty()) {
                    foreach ($booking->sessions as $session) {
                        if (in_array($session->status?->value, ['completed', 'cancelled', 'skipped', 'superseded'], true)) {
                            continue;
                        }
                        $start = CarbonImmutable::parse($session->scheduled_date->format('Y-m-d').' '.$session->scheduled_time, config('cleaning_booking_v2.timezone'));
                        $busy[$worker->id][] = [$start->timestamp, $start->addMinutes((int) ceil($session->duration_hours * 60))->timestamp];
                    }
                } else {
                    $start = CarbonImmutable::parse($booking->scheduled_date->format('Y-m-d').' '.$booking->scheduled_time, config('cleaning_booking_v2.timezone'));
                    $busy[$worker->id][] = [$start->timestamp, $start->addMinutes((int) ceil($booking->estimated_hours * 60 / max(1, $booking->number_of_workers)))->timestamp];
                }
            }
        }
        foreach ($demand as $time => $required) {
            $at = CarbonImmutable::parse($time);
            $available = $workers->filter(static function (Worker $worker) use ($at, $busy): bool {
                if (! $worker->isAvailableAt($at)) {
                    return false;
                }
                foreach ($busy[$worker->id] ?? [] as [$start, $end]) {
                    if ($at->timestamp >= $start && $at->timestamp < $end) {
                        return false;
                    }
                }

                return true;
            })->count();
            // The remaining team can be assembled by open worker dispatch.
            if ($available < min($required, count($ids))) {
                $this->invalid('العمال المفضلون غير متاحين طوال الموعد. اختر موعداً أو عاملاً آخر.');
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['preferredWorkerIds' => [$message]]);
    }
}
