<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Models\CleaningFinancialPenalty;
use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class CleaningFinancialPenaltyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const CANONICAL_TYPE = 'cleaning.financial_penalty.applied';

    public function __construct(
        private readonly CleaningFinancialPenalty $penalty,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels(self::CANONICAL_TYPE, $notifiable);
    }

    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: self::CANONICAL_TYPE,
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: self::CANONICAL_TYPE,
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    private function templateContext(): array
    {
        return [
            'amount' => number_format((float) $this->penalty->amount, 0, '.', ','),
            'currency' => (string) config('app.currency', 'SYP'),
            'booking_number' => (string) $this->penalty->booking?->booking_number,
        ];
    }

    private function extraData(): array
    {
        return [
            'penaltyId' => (int) $this->penalty->id,
            'bookingId' => (int) $this->penalty->cleaning_booking_id,
            'orderId' => (int) $this->penalty->cleaning_booking_id,
            'bookingNumber' => (string) $this->penalty->booking?->booking_number,
            'workerId' => (int) $this->penalty->worker_id,
            'amount' => (float) $this->penalty->amount,
            'currency' => (string) config('app.currency', 'SYP'),
            'notes' => (string) $this->penalty->notes,
            'cancellationReason' => $this->penalty->cancellation_reason_snapshot,
            'cancellationOffsetMinutes' => $this->penalty->cancellation_offset_minutes,
            'financialSource' => (string) $this->penalty->financial_source,
            'action' => 'financial_penalty_applied',
            'deep_link_target' => 'cleaning_booking_details',
            'occurred_at' => $this->penalty->applied_at?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
