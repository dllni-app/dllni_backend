<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Schemas;

use App\Enums\SOSStatus;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

final class SosAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)
                ->schema([
                    TextEntry::make('id')->label('المعرّف'),
                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->color(fn (mixed $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state)),
                    TextEntry::make('reporter_role')
                        ->label('صفة المُبلِّغ')
                        ->badge()
                        ->state(fn (SosAlert $record): string => SosAlertsTable::roleLabel($record)),
                    TextEntry::make('booking.booking_number')->label('طلب التنظيف')->placeholder('-'),
                    TextEntry::make('user.name')->label('صاحب البلاغ')->placeholder('-'),
                    TextEntry::make('emergency_type')
                        ->label('نوع البلاغ')
                        ->badge()
                        ->formatStateUsing(fn (mixed $state): string => SosAlertsTable::emergencyLabel($state)),
                    TextEntry::make('message')->label('الرسالة')->columnSpanFull(),
                    TextEntry::make('triggered_at')->label('وقت الإرسال')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledged_at')->label('وقت الاستلام')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledgedBy.name')->label('تم الاستلام بواسطة')->placeholder('-'),
                    TextEntry::make('resolved_at')->label('وقت الحل')->dateTime()->placeholder('-'),
                    TextEntry::make('resolvedBy.name')->label('تم الحل بواسطة')->placeholder('-'),
                    TextEntry::make('resolution_note')->label('ملاحظة الحل')->columnSpanFull()->placeholder('-'),
                    TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime(),
                    TextEntry::make('updated_at')->label('تاريخ التحديث')->dateTime(),
                ]),
        ]);
    }

    private static function statusLabel(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'قيد الانتظار',
            SOSStatus::Triggered => 'جديد',
            SOSStatus::Acknowledged => 'تم الاستلام',
            SOSStatus::Resolved => 'تم الحل',
            default => '-',
        };
    }

    private static function statusColor(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'warning',
            SOSStatus::Acknowledged => 'info',
            SOSStatus::Resolved => 'success',
            SOSStatus::Triggered => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeStatus(SOSStatus|string|null $status): ?SOSStatus
    {
        if ($status instanceof SOSStatus || $status === null) {
            return $status;
        }

        return SOSStatus::tryFrom($status);
    }
}
