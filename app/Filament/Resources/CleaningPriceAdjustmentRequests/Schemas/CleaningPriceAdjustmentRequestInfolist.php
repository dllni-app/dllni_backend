<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningPriceAdjustmentRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;

final class CleaningPriceAdjustmentRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الطلب')
                    ->schema([
                        TextEntry::make('id')->label('#'),
                        TextEntry::make('status')
                            ->label('حالة طلب التعديل')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                            ->color(fn ($state): string => self::statusColor($state)),
                        TextEntry::make('created_at')->label('تاريخ الطلب')->dateTime('Y-m-d H:i'),
                        TextEntry::make('reviewed_at')->label('تاريخ المراجعة')->dateTime('Y-m-d H:i')->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('الحجز والأطراف')
                    ->schema([
                        TextEntry::make('booking.booking_number')->label('رقم الحجز')->placeholder('-'),
                        TextEntry::make('booking.status')
                            ->label('حالة الحجز')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => self::bookingStatusLabel($state))
                            ->color(fn ($state): string => self::bookingStatusColor($state))
                            ->placeholder('-'),
                        TextEntry::make('booking.customer.name')->label('العميل')->placeholder('-'),
                        TextEntry::make('worker.first_name')->label('العامل')->placeholder('-'),
                        TextEntry::make('worker.user.phone')->label('هاتف العامل')->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('تفاصيل السعر')
                    ->schema([
                        TextEntry::make('old_total_price')->label('السعر الحالي')->money(config('app.currency', 'SYP')),
                        TextEntry::make('proposed_total_price')->label('السعر المقترح')->money(config('app.currency', 'SYP')),
                        TextEntry::make('admin_final_total_price')->label('السعر النهائي المعتمد')->money(config('app.currency', 'SYP'))->placeholder('-'),
                        TextEntry::make('reason')->label('سبب العامل')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('admin_note')->label('ملاحظة الإدارة')->placeholder('-')->columnSpanFull(),
                        TextEntry::make('reviewedBy.name')->label('راجعه')->placeholder('-'),
                    ])
                    ->columns(2),
            ]);
    }

    private static function statusLabel(CleaningPriceAdjustmentRequestStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->label() ?? '-';
    }

    private static function statusColor(CleaningPriceAdjustmentRequestStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            CleaningPriceAdjustmentRequestStatus::Pending => 'warning',
            CleaningPriceAdjustmentRequestStatus::Approved => 'success',
            CleaningPriceAdjustmentRequestStatus::Rejected => 'danger',
            CleaningPriceAdjustmentRequestStatus::ResolvedWithoutChange => 'info',
            CleaningPriceAdjustmentRequestStatus::Cancelled => 'gray',
            default => 'gray',
        };
    }

    private static function normalizeStatus(CleaningPriceAdjustmentRequestStatus|string|null $status): ?CleaningPriceAdjustmentRequestStatus
    {
        if ($status instanceof CleaningPriceAdjustmentRequestStatus || $status === null) {
            return $status;
        }

        return CleaningPriceAdjustmentRequestStatus::tryFrom($status);
    }

    private static function bookingStatusLabel(CleaningBookingStatus|string|null $status): string
    {
        $status = self::normalizeBookingStatus($status);

        return $status?->label() ?? '-';
    }

    private static function bookingStatusColor(CleaningBookingStatus|string|null $status): string
    {
        return match (self::normalizeBookingStatus($status)) {
            CleaningBookingStatus::Pending => 'warning',
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation => 'info',
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::TimeExtensionRequested => 'primary',
            CleaningBookingStatus::AwaitingCustomerCompletion => 'gray',
            CleaningBookingStatus::Completed => 'success',
            CleaningBookingStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeBookingStatus(CleaningBookingStatus|string|null $status): ?CleaningBookingStatus
    {
        if ($status instanceof CleaningBookingStatus || $status === null) {
            return $status;
        }

        return CleaningBookingStatus::tryFrom($status);
    }
}
