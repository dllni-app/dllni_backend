<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseResolution;
use App\Enums\SupportCaseStatus;
use App\Enums\SystemAlertStatus;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class SupportCaseService
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, UploadedFile> $attachments
     */
    public function create(User $reporter, array $data, array $attachments = []): SupportCase
    {
        $booking = CleaningBooking::query()->findOrFail((int) $data['bookingId']);
        $reporterRole = $this->resolveReporterRole($reporter, $booking);
        $kind = SupportCaseKind::from((string) $data['kind']);

        if ($kind === SupportCaseKind::Complaint && $reporterRole !== SupportCaseReporterRole::Customer) {
            abort(403, 'Only the booking customer can create a complaint.');
        }

        $this->validateBookingState($booking, $kind);

        $clientRequestId = filled($data['clientRequestId'] ?? null)
            ? trim((string) $data['clientRequestId'])
            : null;

        $existing = $this->findExistingRequest(
            reporter: $reporter,
            booking: $booking,
            kind: $kind,
            clientRequestId: $clientRequestId,
        );

        if ($existing instanceof SupportCase) {
            return $existing->load(['reporter', 'booking', 'messages.sender', 'events.actor', 'media']);
        }

        $supportCase = DB::transaction(function () use ($reporter, $reporterRole, $booking, $kind, $data, $clientRequestId): SupportCase {
            $previousStatus = $booking->status instanceof CleaningBookingStatus
                ? $booking->status->value
                : (string) $booking->status;

            $supportCase = SupportCase::query()->create([
                'case_number' => $this->generateCaseNumber($kind),
                'kind' => $kind,
                'priority' => $kind === SupportCaseKind::Emergency
                    ? SupportCasePriority::Critical
                    : SupportCasePriority::Normal,
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'reporter_id' => $reporter->id,
                'reporter_role' => $reporterRole,
                'category' => $kind === SupportCaseKind::Emergency
                    ? $data['emergencyType']
                    : $data['category'],
                'description' => trim((string) $data['description']),
                'status' => SupportCaseStatus::New,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'worker_earnings_frozen' => $kind === SupportCaseKind::Complaint,
                'client_request_id' => $clientRequestId,
                'context' => [
                    'booking_status_before_dispute' => $previousStatus,
                ],
            ]);

            SupportCaseEvent::query()->create([
                'support_case_id' => $supportCase->id,
                'actor_id' => $reporter->id,
                'event_type' => 'created',
                'to_status' => SupportCaseStatus::New->value,
                'metadata' => [
                    'kind' => $kind->value,
                    'reporter_role' => $reporterRole->value,
                ],
            ]);

            if ($kind === SupportCaseKind::Complaint) {
                $booking->update(['status' => CleaningBookingStatus::UnderDispute]);
            }

            SystemAlert::query()->create([
                'booking_id' => $booking->id,
                'booking_type' => CleaningBooking::class,
                'alert_type' => $kind === SupportCaseKind::Emergency
                    ? AlertType::SOSTriggered
                    : AlertType::CleaningBookingDispute,
                'severity' => $kind === SupportCaseKind::Emergency
                    ? AlertSeverity::Critical
                    : AlertSeverity::High,
                'status' => SystemAlertStatus::New,
                'payload' => [
                    'source' => 'support_case',
                    'support_case_id' => $supportCase->id,
                    'case_number' => $supportCase->case_number,
                    'kind' => $kind->value,
                    'reporter_id' => $reporter->id,
                    'reporter_role' => $reporterRole->value,
                    'booking_id' => $booking->id,
                    'category' => $supportCase->category,
                    'message' => $supportCase->description,
                    'latitude' => $supportCase->latitude,
                    'longitude' => $supportCase->longitude,
                ],
            ]);

            return $supportCase;
        });

        $this->attachFiles($supportCase, $attachments);

        return $supportCase->fresh()->load(['reporter', 'booking', 'messages.sender', 'events.actor', 'media']);
    }

    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function addMessage(
        SupportCase $supportCase,
        User $sender,
        SupportCaseReporterRole $senderRole,
        string $body,
        array $attachments = [],
    ): SupportCaseMessage {
        if ($supportCase->status?->isTerminal()) {
            throw ValidationException::withMessages([
                'supportCase' => ['Cannot add a message to a resolved or closed support case.'],
            ]);
        }

        $message = DB::transaction(function () use ($supportCase, $sender, $senderRole, $body): SupportCaseMessage {
            $message = SupportCaseMessage::query()->create([
                'support_case_id' => $supportCase->id,
                'sender_id' => $sender->id,
                'sender_role' => $senderRole,
                'body' => trim($body),
            ]);

            SupportCaseEvent::query()->create([
                'support_case_id' => $supportCase->id,
                'actor_id' => $sender->id,
                'event_type' => 'message_added',
                'from_status' => $supportCase->status?->value,
                'to_status' => $supportCase->status?->value,
                'metadata' => ['sender_role' => $senderRole->value],
            ]);

            return $message;
        });

        $this->attachFiles($message, $attachments);

        return $message->fresh()->load(['sender', 'media']);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function transition(
        SupportCase $supportCase,
        SupportCaseStatus $status,
        ?User $actor = null,
        array $metadata = [],
    ): SupportCase {
        $fromStatus = $supportCase->status?->value;

        DB::transaction(function () use ($supportCase, $status, $actor, $metadata, $fromStatus): void {
            $updates = ['status' => $status];

            if ($status === SupportCaseStatus::Acknowledged && $supportCase->acknowledged_at === null) {
                $updates['acknowledged_at'] = now();
                $updates['acknowledged_by'] = $actor?->id;
            }

            $supportCase->update($updates);

            SupportCaseEvent::query()->create([
                'support_case_id' => $supportCase->id,
                'actor_id' => $actor?->id,
                'event_type' => 'status_changed',
                'from_status' => $fromStatus,
                'to_status' => $status->value,
                'metadata' => $metadata,
            ]);
        });

        return $supportCase->fresh();
    }

    public function resolve(
        SupportCase $supportCase,
        SupportCaseResolution $resolution,
        ?string $note,
        ?User $actor = null,
    ): SupportCase {
        $fromStatus = $supportCase->status?->value;

        DB::transaction(function () use ($supportCase, $resolution, $note, $actor, $fromStatus): void {
            $supportCase->update([
                'status' => SupportCaseStatus::Resolved,
                'resolution' => $resolution,
                'resolution_note' => filled($note) ? trim((string) $note) : null,
                'resolved_at' => now(),
                'resolved_by' => $actor?->id,
                'worker_earnings_frozen' => $resolution === SupportCaseResolution::WorkerPenalty,
            ]);

            SupportCaseEvent::query()->create([
                'support_case_id' => $supportCase->id,
                'actor_id' => $actor?->id,
                'event_type' => 'resolved',
                'from_status' => $fromStatus,
                'to_status' => SupportCaseStatus::Resolved->value,
                'metadata' => [
                    'resolution' => $resolution->value,
                    'note' => $note,
                ],
            ]);

            $this->restoreBookingAfterResolution($supportCase);
        });

        return $supportCase->fresh();
    }

    public function releaseWorkerEarnings(SupportCase $supportCase, ?User $actor = null): SupportCase
    {
        $supportCase->update(['worker_earnings_frozen' => false]);

        SupportCaseEvent::query()->create([
            'support_case_id' => $supportCase->id,
            'actor_id' => $actor?->id,
            'event_type' => 'worker_earnings_released',
            'from_status' => $supportCase->status?->value,
            'to_status' => $supportCase->status?->value,
        ]);

        return $supportCase->fresh();
    }

    public function reporterRoleFor(User $user, SupportCase $supportCase): SupportCaseReporterRole
    {
        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return SupportCaseReporterRole::Admin;
        }

        $booking = $supportCase->booking;

        if ($booking instanceof CleaningBooking) {
            return $this->resolveReporterRole($user, $booking);
        }

        abort(403, 'You cannot access this support case.');
    }

    private function resolveReporterRole(User $reporter, CleaningBooking $booking): SupportCaseReporterRole
    {
        if ((int) $booking->customer_id === (int) $reporter->id) {
            return SupportCaseReporterRole::Customer;
        }

        $workerId = $reporter->worker?->id;
        $isAssignedWorker = $workerId !== null && (
            (int) $booking->worker_id === (int) $workerId
            || $booking->workerAssignments()->where('worker_id', $workerId)->exists()
        );

        if ($isAssignedWorker) {
            return SupportCaseReporterRole::Worker;
        }

        if ($reporter->hasAnyRole(['admin', 'Super Admin'])) {
            return SupportCaseReporterRole::Admin;
        }

        abort(403, 'You are not a participant in this booking.');
    }

    private function validateBookingState(CleaningBooking $booking, SupportCaseKind $kind): void
    {
        $status = $booking->status instanceof CleaningBookingStatus
            ? $booking->status
            : CleaningBookingStatus::tryFrom((string) $booking->status);

        if ($status === CleaningBookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'bookingId' => ['Cannot create a support case for a cancelled booking.'],
            ]);
        }

        if ($kind === SupportCaseKind::Emergency && $status === CleaningBookingStatus::Completed) {
            throw ValidationException::withMessages([
                'bookingId' => ['Cannot create an emergency case for a completed booking.'],
            ]);
        }
    }

    private function findExistingRequest(
        User $reporter,
        CleaningBooking $booking,
        SupportCaseKind $kind,
        ?string $clientRequestId,
    ): ?SupportCase {
        if ($clientRequestId !== null) {
            $existing = SupportCase::query()
                ->where('reporter_id', $reporter->id)
                ->where('client_request_id', $clientRequestId)
                ->first();

            if ($existing instanceof SupportCase) {
                return $existing;
            }
        }

        if ($kind !== SupportCaseKind::Emergency) {
            return null;
        }

        return SupportCase::query()
            ->where('kind', SupportCaseKind::Emergency->value)
            ->where('reporter_id', $reporter->id)
            ->where('booking_id', $booking->id)
            ->where('booking_type', CleaningBooking::class)
            ->whereIn('status', SupportCaseStatus::activeValues())
            ->latest('id')
            ->first();
    }

    private function generateCaseNumber(SupportCaseKind $kind): string
    {
        $prefix = $kind === SupportCaseKind::Emergency ? 'SOS' : 'CMP';

        do {
            $number = sprintf('%s-%s-%s', $prefix, now()->format('Ymd'), Str::upper(Str::random(6)));
        } while (SupportCase::query()->where('case_number', $number)->exists());

        return $number;
    }

    private function restoreBookingAfterResolution(SupportCase $supportCase): void
    {
        if ($supportCase->kind !== SupportCaseKind::Complaint) {
            return;
        }

        $booking = $supportCase->booking;
        if (! $booking instanceof CleaningBooking || $booking->status !== CleaningBookingStatus::UnderDispute) {
            return;
        }

        $previous = CleaningBookingStatus::tryFrom((string) data_get($supportCase->context, 'booking_status_before_dispute'));
        $booking->update([
            'status' => $previous ?? CleaningBookingStatus::Completed,
        ]);
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function attachFiles(SupportCase|SupportCaseMessage $model, array $files): void
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $model->addMedia($file)->toMediaCollection('attachments');
        }
    }
}
