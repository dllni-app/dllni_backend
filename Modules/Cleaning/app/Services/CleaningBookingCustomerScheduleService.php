<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
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

        // The presenter owns the legacy whole-schedule replacement flag. Keep it
        // separate from the newer per-session capability so an accepted worker
        // can remain attached to a moved future day without reopening bulk
        // schedule replacement before the event has started.
        $legacyBulkReschedule = collect($schedule['sessions'])
            ->contains(static fn ($payload): bool => is_array($payload)
                && (bool) ($payload['canReschedule'] ?? false));

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

        $hasHistoricalExecution = $sessions->contains(static function (CleaningBookingSession $session): bool {
            $status = $session->status?->value ?? (string) $session->status;

            return in_array($status, [
                CleaningBookingSessionStatus::Completed->value,
                CleaningBookingSessionStatus::Cancelled->value,
                CleaningBookingSessionStatus::Skipped->value,
            ], true);
        });

        // Existing Flutter builds use `canReschedule` as one booking-level entry
        // point repeated on each day. Before execution starts, preserve the old
        // rule: any accepted worker locks bulk schedule replacement. Once the
        // event has execution history, expose that entry point again when at
        // least one remaining day can be moved individually. The exact editable
        // day is always expressed by `canRescheduleSession`.
        $legacyRescheduleEntryPoint = $legacyBulkReschedule
            || ($hasHistoricalExecution && $anyReschedulable);

        foreach ($schedule['sessions'] as $index => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $sessionId = (int) ($payload['sessionId'] ?? $payload['id'] ?? 0);
            $capability = $sessionCapabilities[$sessionId] ?? [
                'canRescheduleSession' => false,
                'canChangeDuration' => false,
            ];

            $schedule['sessions'][$index]['canReschedule'] = $legacyRescheduleEntryPoint;
            $schedule['sessions'][$index]['canRescheduleSession'] = $capability['canRescheduleSession'];
            $schedule['sessions'][$index]['canChangeDuration'] = $capability['canChangeDuration'];
        }

        if (is_array($schedule['nextSession'] ?? null)) {
            $nextId = (int) ($schedule['nextSession']['sessionId'] ?? $schedule['nextSession']['id'] ?? 0);
            $capability = $sessionCapabilities[$nextId] ?? [
                'canRescheduleSession' => false,
                'canChangeDuration' => false,
            ];
            $schedule['nextSession']['canReschedule'] = $legacyRescheduleEntryPoint;
            $schedule['nextSession']['canRescheduleSession'] = $capability['canRescheduleSession'];
            $schedule['nextSession']['canChangeDuration'] = $capability['canChangeDuration'];
        }

        $schedule['canReschedule'] = $legacyRescheduleEntryPoint;

        return $schedule;
    }
}
