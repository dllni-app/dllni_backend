<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SOSStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SosAlert;
use App\Models\SystemAlert;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningBookingSessionSosService
{
    /**
     * @param  array{emergency_type:string,message:string,latitude?:mixed,longitude?:mixed}  $payload
     */
    public function trigger(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User $actor,
        array $payload,
    ): SosAlert {
        $session = CleaningBookingSession::query()
            ->whereKey($session->id)
            ->where('cleaning_booking_id', $booking->id)
            ->first();

        if (! $session instanceof CleaningBookingSession) {
            throw new InvalidArgumentException('Session does not belong to this booking.');
        }
        if ($session->isTerminal()) {
            throw new InvalidArgumentException('Cannot create an SOS request for a closed session.');
        }

        $actorRole = $this->authorizeActor($booking, $session, $actor);
        $source = 'booking_session:'.$session->id;

        $existing = SosAlert::query()
            ->where('user_id', $actor->id)
            ->where('booking_id', $booking->id)
            ->where('booking_type', CleaningBooking::class)
            ->where('source', $source)
            ->whereIn('status', [SOSStatus::Triggered->value, SOSStatus::Acknowledged->value])
            ->latest('id')
            ->first();

        if ($existing instanceof SosAlert) {
            return $existing;
        }

        return DB::transaction(function () use (
            $booking,
            $session,
            $actor,
            $actorRole,
            $payload,
            $source,
        ): SosAlert {
            $sos = SosAlert::query()->create([
                'user_id' => $actor->id,
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'emergency_type' => $payload['emergency_type'],
                'message' => $payload['message'],
                'source' => $source,
                'status' => SOSStatus::Triggered->value,
                'latitude' => $payload['latitude'] ?? null,
                'longitude' => $payload['longitude'] ?? null,
                'triggered_at' => now(),
            ]);

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => AlertType::SOSTriggered->value,
                'severity' => AlertSeverity::Critical->value,
                'status' => SystemAlertStatus::New->value,
                'payload' => [
                    'source' => 'cleaning_session_sos',
                    'sos_alert_id' => $sos->id,
                    'user_id' => $actor->id,
                    'actor_role' => $actorRole,
                    'booking_id' => $booking->id,
                    'session_id' => $session->id,
                    'session_sequence' => $session->sequence,
                    'scheduled_date' => $session->scheduled_date?->format('Y-m-d'),
                    'message' => $payload['message'],
                    'emergency_type' => $payload['emergency_type'],
                    'latitude' => $payload['latitude'] ?? null,
                    'longitude' => $payload['longitude'] ?? null,
                ],
            ]);

            return $sos->fresh() ?? $sos;
        });
    }

    private function authorizeActor(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        User $actor,
    ): string {
        if ((int) $booking->customer_id === (int) $actor->id) {
            return 'customer';
        }

        $worker = $actor->worker;
        if (! $worker instanceof Worker) {
            abort(403, 'You are not allowed to create an SOS for this session.');
        }

        $assigned = CleaningBookingSessionWorkerAssignment::query()
            ->where('cleaning_booking_session_id', $session->id)
            ->where('worker_id', $worker->id)
            ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
            ->exists();

        if (! $assigned) {
            abort(403, 'Worker is not assigned to this session.');
        }

        return 'worker';
    }
}
