<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerHomeLocationStatus: string
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => __('cleaning_admin.workers.home_location_status.approved'),
            self::Pending => __('cleaning_admin.workers.home_location_status.pending'),
            self::Rejected => __('cleaning_admin.workers.home_location_status.rejected'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Pending => 'warning',
            self::Rejected => 'danger',
        };
    }
}
