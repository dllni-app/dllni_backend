<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

final class CleaningBookingCustomerScheduleService
{
    public function __construct(
        private readonly CleaningBookingSchedulePresenter $presenter,
        private readonly CleaningBookingSessionCapabilityService $capabilities,
    ) {}

    /** @return array<string, mixed> */
    public function present(CleaningBooking $booking): array
    {
        $schedule = $this->presenter->present($booking);

        if (! $booking->isEventAssistanceBooking() || ! is_array($schedule['sessions'] ?? null)) {
            return $schedule;
        }

        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->with('workerAssignments')
            ->get()
            ->keyBy('id');
        $anyReschedulable = false;

        foreach ($schedule['sessions'] as $index => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $sessionId = (int) ($payload['sessionId'] ?? $payload['id'] ?? 0);
            $session = $sessions->get($sessionId);
            if (! $session instanceof CleaningBookingSession) {
                $schedule['sessions'][$index]['canReschedule'] = false;
                $schedule['sessions'][$index]['canChangeDuration'] = false;

                continue;
            }

            $canReschedule = $this->capabilities->canCustomerRescheduleEventSession($booking, $session);
            $canChangeDuration = $this->capabilities->canCustomerChangeEventSessionDuration($booking, $session);
            $schedule['sessions'][$index]['canReschedule'] = $canReschedule;
            $schedule['sessions'][$index]['canChangeDuration'] = $canChangeDuration;
            $anyReschedulable = $anyReschedulable || $canReschedule;
        }

        if (is_array($schedule['nextSession'] ?? null)) {
            $nextId = (int) ($schedule['nextSession']['sessionId'] ?? $schedule['nextSession']['id'] ?? 0);
            $next = $sessions->get($nextId);
            if ($next instanceof CleaningBookingSession) {
                $schedule['nextSession']['canReschedule'] = $this->capabilities
                    ->canCustomerRescheduleEventSession($booking, $next);
                $schedule['nextSession']['canChangeDuration'] = $this->capabilities
                    ->canCustomerChangeEventSessionDuration($booking, $next);
            }
        }

        $schedule['canReschedule'] = $anyReschedulable;

        return $schedule;
    }
}
