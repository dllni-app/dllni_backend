<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseStatus;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\SosAlert;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class LegacySupportCaseSyncService
{
    public function syncDispute(Dispute $dispute): ?SupportCase
    {
        if (! in_array($dispute->booking_type, [
            'cleaning_booking',
            CleaningBooking::class,
            'event_booking',
            EventBooking::class,
        ], true)) {
            return null;
        }

        $booking = $dispute->relationLoaded('booking')
            ? $dispute->booking
            : $dispute->booking()->first();

        return SupportCase::query()->updateOrCreate(
            ['legacy_type' => 'dispute', 'legacy_id' => $dispute->id],
            [
                'case_number' => $dispute->ticket_number ?: 'DSP-'.str_pad((string) $dispute->id, 8, '0', STR_PAD_LEFT),
                'kind' => SupportCaseKind::Complaint,
                'priority' => SupportCasePriority::Normal,
                'booking_id' => $dispute->booking_id,
                'booking_type' => $dispute->booking_type,
                'reporter_id' => $booking?->customer_id,
                'reporter_role' => SupportCaseReporterRole::Customer,
                'category' => $dispute->category?->value ?? $dispute->category,
                'description' => $dispute->description ?: 'Legacy dispute',
                'status' => $this->mapDisputeStatus($dispute->status?->value ?? $dispute->status),
                'resolution' => $this->mapDisputeResolution($dispute->resolution?->value ?? $dispute->resolution),
                'worker_earnings_frozen' => (bool) $dispute->worker_earnings_frozen,
            ],
        );
    }

    public function syncDisputeMessage(DisputeMessage $message): void
    {
        $supportCase = $this->syncDispute($message->dispute);
        if (! $supportCase instanceof SupportCase) {
            return;
        }

        SupportCaseMessage::query()->updateOrCreate(
            [
                'support_case_id' => $supportCase->id,
                'sender_id' => $message->sender_id,
                'created_at' => $message->created_at,
            ],
            [
                'sender_role' => $this->normalizeReporterRole((string) $message->sender_type),
                'body' => $message->body,
                'updated_at' => $message->updated_at,
            ],
        );
    }

    public function syncSosAlert(SosAlert $alert): SupportCase
    {
        $booking = $alert->relationLoaded('booking')
            ? $alert->booking
            : $alert->booking()->first();
        $source = (string) $alert->source;
        $isWorker = str_contains($source, 'worker')
            || ($booking instanceof CleaningBooking
                && $alert->user_id !== null
                && (int) $booking->customer_id !== (int) $alert->user_id);

        return SupportCase::query()->updateOrCreate(
            ['legacy_type' => 'sos_alert', 'legacy_id' => $alert->id],
            [
                'case_number' => 'SOS-'.str_pad((string) $alert->id, 8, '0', STR_PAD_LEFT),
                'kind' => SupportCaseKind::Emergency,
                'priority' => SupportCasePriority::Critical,
                'booking_id' => $alert->booking_id,
                'booking_type' => $alert->booking_type,
                'reporter_id' => $alert->user_id,
                'reporter_role' => $isWorker
                    ? SupportCaseReporterRole::Worker
                    : SupportCaseReporterRole::Customer,
                'category' => $alert->emergency_type?->value ?? $alert->emergency_type,
                'description' => $alert->message ?: 'Legacy SOS alert',
                'status' => $this->mapSosStatus($alert->status?->value ?? $alert->status),
                'latitude' => $alert->latitude,
                'longitude' => $alert->longitude,
                'acknowledged_by' => $alert->acknowledged_by,
                'acknowledged_at' => $alert->acknowledged_at,
                'resolved_by' => $alert->resolved_by,
                'resolved_at' => $alert->resolved_at,
                'resolution_note' => $alert->resolution_note,
            ],
        );
    }

    private function mapDisputeStatus(mixed $status): SupportCaseStatus
    {
        return match ((string) $status) {
            'under_review' => SupportCaseStatus::UnderReview,
            'resolved' => SupportCaseStatus::Resolved,
            'closed', 'rejected' => SupportCaseStatus::Closed,
            default => SupportCaseStatus::New,
        };
    }

    private function mapSosStatus(mixed $status): SupportCaseStatus
    {
        return match ((string) $status) {
            'acknowledged' => SupportCaseStatus::Acknowledged,
            'resolved' => SupportCaseStatus::Resolved,
            default => SupportCaseStatus::New,
        };
    }

    private function mapDisputeResolution(mixed $resolution): ?string
    {
        return match ((string) $resolution) {
            'worker_penalty' => 'worker_penalty',
            'full_refund', 'partial_refund' => 'refund',
            'dismissed' => 'dismissed',
            default => null,
        };
    }

    private function normalizeReporterRole(string $role): SupportCaseReporterRole
    {
        return match (strtolower(trim($role))) {
            'worker' => SupportCaseReporterRole::Worker,
            'admin', 'support' => SupportCaseReporterRole::Admin,
            default => SupportCaseReporterRole::Customer,
        };
    }
}
