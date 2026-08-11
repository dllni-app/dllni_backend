<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningPriceAdjustmentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ResolvedWithoutChange = 'resolved_without_change';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار مراجعة الإدارة',
            self::Approved => 'تمت الموافقة',
            self::Rejected => 'مرفوض',
            self::ResolvedWithoutChange => 'تم التواصل بدون تعديل السعر',
            self::Cancelled => 'ملغى',
        };
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return [
            self::Approved->value,
            self::Rejected->value,
            self::ResolvedWithoutChange->value,
            self::Cancelled->value,
        ];
    }
}
