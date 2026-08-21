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
        $party = $this->penalty->penalized_role === CleaningFinancialPenalty::ROLE_CUSTOMER ? 'المستخدم' : 'العامل';

        return [
            'type' => 'cleaning.financial_penalty.applied',
            'title' => 'تم تسجيل غرامة مالية',
            'message' => "تم تسجيل غرامة إلغاء على {$party} بقيمة {$amount} ل.س للطلب رقم {$bookingNumber}. الغرامة بانتظار مراجعة الإدارة.",
            'bookingId' => (int) $this->penalty->cleaning_booking_id,
            'bookingNumber' => $bookingNumber,
            'penaltyId' => (int) $this->penalty->id,
            'amount' => (float) $this->penalty->amount,
            'currency' => 'SYP',
            'penalizedRole' => $this->penalty->penalized_role,
            'reviewStatus' => $this->penalty->review_status,
            'financialSource' => $this->penalty->financial_source,
            'notes' => $this->penalty->notes,
            'occurredAt' => $this->penalty->applied_at?->toIso8601String() ?? now()->toIso8601String(),
            'deep_link_target' => 'cleaning_booking_details',
        ];
    }
}
