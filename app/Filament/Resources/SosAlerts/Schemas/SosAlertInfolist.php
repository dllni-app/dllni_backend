<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts\Schemas;

use App\Enums\SOSStatus;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SosAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تفاصيل تنبيه الطوارئ')
                ->schema([
                    TextEntry::make('id')->label('المعرّف'),
                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->color(fn (mixed $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (mixed $state): string => self::statusLabel($state)),
                    TextEntry::make('source')->label('المصدر'),
                    TextEntry::make('message')->label('الرسالة')->columnSpanFull(),
                    TextEntry::make('order.order_number')->label('الطلب')->placeholder('-'),
                    TextEntry::make('user.name')->label('المستخدم')->placeholder('-'),
                    TextEntry::make('triggered_at')->label('وقت الإطلاق')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledged_at')->label('وقت الاستلام')->dateTime()->placeholder('-'),
                    TextEntry::make('acknowledgedBy.name')->label('تم الاستلام بواسطة')->placeholder('-'),
                    TextEntry::make('resolved_at')->label('وقت الحل')->dateTime()->placeholder('-'),
                    TextEntry::make('resolvedBy.name')->label('تم الحل بواسطة')->placeholder('-'),
                    TextEntry::make('resolution_note')->label('ملاحظة الحل')->columnSpanFull()->placeholder('-'),
                    TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime(),
                    TextEntry::make('updated_at')->label('تاريخ التحديث')->dateTime(),
                ])
                ->columns(2),
        ]);
    }

    private static function statusLabel(SOSStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            SOSStatus::Pending => 'قيد الانتظار',
            SOSStatus::Triggered => 'تم الإطلاق',
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
