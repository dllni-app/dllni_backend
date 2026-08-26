<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Throwable;

final class CleaningBookingScheduleService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{date:string,time:string,hours:float}>
     */
    public function definitions(array $payload, ?CleaningBooking $booking = null): array
    {
        $sessions = data_get($payload, 'schedule.sessions');

        if (is_array($sessions) && $sessions !== []) {
            $definitions = [];

            foreach ($sessions as $session) {
                if (! is_array($session)) {
                    continue;
                }

                $date = mb_trim((string) ($session['date'] ?? ''));
                $time = mb_trim((string) ($session['time'] ?? ''));
                $hours = is_numeric($session['hours'] ?? null) ? (float) $session['hours'] : 0.0;

                if ($date === '' || $time === '' || $hours <= 0) {
                    continue;
                }

                $definitions[] = [
                    'date' => $date,
                    'time' => mb_substr($time, 0, 5),
                    'hours' => round($hours, 2),
                ];
            }

            usort($definitions, static fn (array $a, array $b): int => strcmp($a['date'].' '.$a['time'], $b['date'].' '.$b['time']));

            return array_values($definitions);
        }

        $date = $payload['scheduledDate'] ?? $booking?->scheduled_date?->toDateString();
        $time = $payload['scheduledTime'] ?? $booking?->scheduled_time;
        $hours = data_get($payload, 'propertyDetails.hours')
            ?? $booking?->total_hours
            ?? $booking?->estimated_hours;

        if (! is_string($date) || mb_trim($date) === '' || ! is_string($time) || mb_trim($time) === '' || ! is_numeric($hours)) {
            return [];
        }

        return [[
            'date' => mb_trim($date),
            'time' => mb_substr(mb_trim($time), 0, 5),
            'hours' => round(max(0.0, (float) $hours), 2),
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sync(CleaningBooking $booking, array $payload): CleaningBooking
    {
        if (! $booking->isEventAssistanceBooking()) {
            return $booking;
        }

        $definitions = $this->definitions($payload, $booking);
        if ($definitions === []) {
            throw ValidationException::withMessages([
                'schedule' => ['Event assistance requires at least one valid execution session.'],
            ]);
        }

        return DB::transaction(function () use ($booking, $definitions): CleaningBooking {
            $locked = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->sessions()->lockForUpdate()->get();
            $hasCommittedWorkers = $locked->workerAssignments()
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->exists();

            if ($existing->isNotEmpty() && $hasCommittedWorkers && ! $this->matches($existing->all(), $definitions)) {
                throw ValidationException::withMessages([
                    'schedule' => ['Event schedule cannot be changed after a worker has accepted the booking.'],
                ]);
            }

            $hasStartedSession = $existing->contains(static fn (CleaningBookingSession $session): bool => ! in_array(
                $session->status,
                [CleaningBookingSessionStatus::Scheduled, CleaningBookingSessionStatus::WorkerAssigned],
                true,
            ));

            if ($hasStartedSession && ! $this->matches($existing->all(), $definitions)) {
                throw ValidationException::withMessages([
                    'schedule' => ['Started or completed event sessions cannot be rescheduled.'],
                ]);
            }

            if (! $this->matches($existing->all(), $definitions)) {
                $locked->sessions()->delete();

                foreach ($definitions as $index => $definition) {
                    $locked->sessions()->create([
                        'sequence' => $index + 1,
                        'scheduled_date' => $definition['date'],
                        'scheduled_time' => $definition['time'],
                        'duration_hours' => $definition['hours'],
                        'status' => $hasCommittedWorkers
                            ? CleaningBookingSessionStatus::WorkerAssigned->value
                            : CleaningBookingSessionStatus::Scheduled->value,
                    ]);
                }
            }

            $first = $definitions[0];
            $totalHours = round(array_sum(array_column($definitions, 'hours')), 2);
            $propertyDetails = is_array($locked->property_details) ? $locked->property_details : [];
            $propertyDetails['hours'] = $totalHours;

            $locked->forceFill([
                'scheduled_date' => $first['date'],
                'scheduled_time' => $first['time'],
                'estimated_hours' => $totalHours,
                'total_hours' => $totalHours,
                'property_details' => $propertyDetails,
            ])->saveQuietly();

            return $locked->fresh(['sessions']);
        });
    }

    /**
     * @param  array<int, CleaningBookingSession>  $existing
     * @param  array<int, array{date:string,time:string,hours:float}>  $definitions
     */
    private function matches(array $existing, array $definitions): bool
    {
        if (count($existing) !== count($definitions)) {
            return false;
        }

        usort($existing, static fn (CleaningBookingSession $a, CleaningBookingSession $b): int => $a->sequence <=> $b->sequence);

        foreach ($definitions as $index => $definition) {
            $session = $existing[$index] ?? null;
            if (! $session instanceof CleaningBookingSession) {
                return false;
            }

            $date = $session->scheduled_date?->toDateString() ?? '';
            $time = mb_substr((string) $session->scheduled_time, 0, 5);

            if ($date !== $definition['date'] || $time !== $definition['time'] || abs((float) $session->duration_hours - $definition['hours']) > 0.001) {
                return false;
            }
        }

        return true;
    }
}
