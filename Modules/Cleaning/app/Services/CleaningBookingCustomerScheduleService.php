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
        $sessionCapabilities = [];
        $anyReschedulable = false;

        foreach ($schedule['sessions'] as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $sessionId = (int) ($payload['sessionId'] ?? $payload['id'] ?? 0);
            $session = $sessions->get($sessionId);
            if (! $session instanceof CleaningBookingSession) {
                continue;
            }

            $canRescheduleSession = $this->capabilities
                ->canCustomerRescheduleEventSession($booking, $session);
            $canChangeDuration = $this->capabilities
                ->canCustomerChangeEventSessionDuration($booking, $session);
            $sessionCapabilities[$sessionId] = [
                'canRescheduleSession' => $canRescheduleSession,
                'canChangeDuration' => $canChangeDuration,
            ];
            $anyReschedulable = $anyReschedulable || $canRescheduleSession;
        }

        foreach ($schedule['sessions'] as $index => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $sessionId = (int) ($payload['sessionId'] ?? $payload['id'] ?? 0);
            $capability = $sessionCapabilities[$sessionId] ?? [
                'canRescheduleSession' => false,
                'canChangeDuration' => false,
            ];

            // Backward compatibility: existing Flutter versions interpret
            // `canReschedule` as a booking-level flag repeated on every day and
            // use every(...) before exposing the edit entry point.
            $schedule['sessions'][$index]['canReschedule'] = $anyReschedulable;
            $schedule['sessions'][$index]['canRescheduleSession'] = $capability['canRescheduleSession'];
            $schedule['sessions'][$index]['canChangeDuration'] = $capability['canChangeDuration'];
        }

        if (is_array($schedule['nextSession'] ?? null)) {
            $nextId = (int) ($schedule['nextSession']['sessionId'] ?? $schedule['nextSession']['id'] ?? 0);
            $capability = $sessionCapabilities[$nextId] ?? [
                'canRescheduleSession' => false,
                'canChangeDuration' => false,
            ];
            $schedule['nextSession']['canReschedule'] = $anyReschedulable;
            $schedule['nextSession']['canRescheduleSession'] = $capability['canRescheduleSession'];
            $schedule['nextSession']['canChangeDuration'] = $capability['canChangeDuration'];
        }

        $schedule['canReschedule'] = $anyReschedulable;

        return $schedule;
    }
}
