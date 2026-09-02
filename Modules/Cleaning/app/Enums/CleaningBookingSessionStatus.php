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
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'مجدولة',
            self::WorkerAssigned => 'تم تعيين العمال',
            self::AwaitingStartVerification => 'بانتظار تحقق البدء',
            self::AwaitingWorkerStartConfirmation => 'بانتظار تأكيد العامل',
            self::InProgress => 'قيد التنفيذ',
            self::AwaitingCustomerCompletion => 'بانتظار تأكيد العميل',
            self::TimeExtensionRequested => 'طلب تمديد وقت',
            self::UnderDispute => 'قيد النزاع',
            self::Completed => 'مكتملة',
            self::Cancelled => 'ملغاة',
            self::Skipped => 'متخطاة',
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
            self::UnderDispute->value,
        ];
    }

    /** @return array<int, string> */
    public static function terminalValues(): array
    {
        return [
            self::Completed->value,
            self::Cancelled->value,
            self::Skipped->value,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::terminalValues(), true);
    }
}
