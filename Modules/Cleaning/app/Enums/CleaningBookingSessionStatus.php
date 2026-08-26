<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningBookingSessionStatus: string
{
    case Scheduled = 'scheduled';
    case WorkerAssigned = 'worker_assigned';
    case AwaitingStartVerification = 'awaiting_start_verification';
    case AwaitingWorkerStartConfirmation = 'awaiting_worker_start_confirmation';
    case InProgress = 'in_progress';
    case AwaitingCustomerCompletion = 'awaiting_customer_completion';
    case TimeExtensionRequested = 'time_extension_requested';
    case UnderDispute = 'under_dispute';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'مجدولة',
            self::WorkerAssigned => 'تم تعيين العامل',
            self::AwaitingStartVerification => 'بانتظار تحقق البدء',
            self::AwaitingWorkerStartConfirmation => 'بانتظار تأكيد العامل',
            self::InProgress => 'قيد التنفيذ',
            self::AwaitingCustomerCompletion => 'بانتظار تأكيد العميل',
            self::TimeExtensionRequested => 'طلب تمديد وقت',
            self::UnderDispute => 'قيد النزاع',
            self::Completed => 'مكتملة',
            self::Cancelled => 'ملغاة',
        };
    }

    /** @return array<int, string> */
    public static function activeValues(): array
    {
        return [
            self::Scheduled->value,
            self::WorkerAssigned->value,
            self::AwaitingStartVerification->value,
            self::AwaitingWorkerStartConfirmation->value,
            self::InProgress->value,
            self::AwaitingCustomerCompletion->value,
            self::TimeExtensionRequested->value,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }
}
