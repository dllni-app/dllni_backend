<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Models\CleaningFinancialPenalty;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class CleaningFinancialPenaltyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CleaningFinancialPenalty $penalty,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->penalty->loadMissing('booking');

        $amount = number_format((float) $this->penalty->amount, 0, '.', ',');
        $bookingNumber = (string) ($this->penalty->booking?->booking_number ?? $this->penalty->cleaning_booking_id);

        return [
            'type' => 'cleaning.financial_penalty.applied',
            'title' => 'تم فرض غرامة مالية',
            'message' => "تم فرض غرامة مالية عليك بقيمة {$amount} بسبب إنهاء الطلب رقم {$bookingNumber} في وقت متأخر.",
            'bookingId' => (int) $this->penalty->cleaning_booking_id,
            'bookingNumber' => $bookingNumber,
            'penaltyId' => (int) $this->penalty->id,
            'amount' => (float) $this->penalty->amount,
            'currency' => 'SYP',
            'financialSource' => $this->penalty->financial_source,
            'notes' => $this->penalty->notes,
            'occurredAt' => $this->penalty->applied_at?->toIso8601String() ?? now()->toIso8601String(),
            'deep_link_target' => 'cleaning_booking_details',
        ];
    }
}
