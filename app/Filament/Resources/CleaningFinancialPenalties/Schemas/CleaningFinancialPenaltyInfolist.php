<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Schemas;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Models\CleaningFinancialPenalty;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CleaningFinancialPenaltyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الغرامة')
                ->columns(3)
                ->schema([
                    TextEntry::make('id')->label('رقم الغرامة'),
                    TextEntry::make('booking.booking_number')
                        ->label('رقم الطلب')
                        ->url(fn (CleaningFinancialPenalty $record): string => CleaningBookingResource::getUrl('view', ['record' => $record->cleaning_booking_id])),
                    TextEntry::make('worker.first_name')
                        ->label('العامل')
                        ->url(fn (CleaningFinancialPenalty $record): string => CleaningWorkerResource::getUrl('view', ['record' => $record->worker_id])),
                    TextEntry::make('amount')->label('قيمة الغرامة')->money(config('app.currency', 'SYP')),
                    TextEntry::make('financial_source')->label('المصدر المالي')->badge()->formatStateUsing(fn (string $state): string => $state === 'deposit' ? 'الإيداع' : 'الدين'),
                    TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn (string $state): string => $state === 'active' ? 'نشطة' : 'مصفرة')->color(fn (string $state): string => $state === 'active' ? 'danger' : 'success'),
                    TextEntry::make('appliedByAdmin.name')->label('أضافها'),
                    TextEntry::make('applied_at')->label('تاريخ الإضافة')->dateTime('Y-m-d H:i'),
                    TextEntry::make('cleared_at')->label('تاريخ التصفير')->dateTime('Y-m-d H:i')->placeholder('-'),
                    TextEntry::make('notes')->label('ملاحظات الإدارة')->columnSpanFull(),
                ]),
            Section::make('بيانات الإلغاء')
                ->columns(3)
                ->schema([
                    TextEntry::make('booking.cancelled_by_role')->label('مصدر الإلغاء')->formatStateUsing(fn (?string $state): string => $state === 'worker' ? 'العامل' : ($state ?? '-')),
                    TextEntry::make('cancellation_offset_minutes')->label('وقت الإلغاء مقارنة بموعد العمل')->formatStateUsing(fn ($state): string => self::timingLabel($state)),
                    TextEntry::make('cancellation_reason_snapshot')->label('سبب الإلغاء')->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    private static function timingLabel(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        $minutes = (int) $value;
        if ($minutes > 0) {
            return "قبل الموعد بـ {$minutes} دقيقة";
        }
        if ($minutes < 0) {
            return 'بعد الموعد بـ '.abs($minutes).' دقيقة';
        }

        return 'في موعد بدء العمل';
    }
}
