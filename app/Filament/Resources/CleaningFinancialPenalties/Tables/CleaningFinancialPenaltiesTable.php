<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Tables;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\CleaningFinancialPenalty;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CleaningFinancialPenaltiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['booking', 'worker.user', 'appliedByAdmin']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('booking.booking_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('worker.user.name')
                    ->label('العامل')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('amount')
                    ->label('قيمة الغرامة')
                    ->money('SYP')
                    ->sortable(),
                TextColumn::make('financial_source')
                    ->label('المصدر المالي')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::SOURCE_DEPOSIT ? 'رصيد الإيداع' : 'الدين')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::SOURCE_DEPOSIT ? 'info' : 'danger'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::STATUS_CLEARED ? 'مصفرّة' : 'فعالة')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::STATUS_CLEARED ? 'success' : 'warning'),
                TextColumn::make('cancellation_offset_minutes')
                    ->label('وقت الإلغاء')
                    ->formatStateUsing(function ($state): string {
                        if ($state === null) {
                            return '-';
                        }
                        $minutes = abs((int) $state);
                        return (int) $state > 0 ? "قبل الموعد بـ {$minutes} دقيقة" : ((int) $state < 0 ? "بعد الموعد بـ {$minutes} دقيقة" : 'عند موعد البدء');
                    })
                    ->toggleable(),
                TextColumn::make('appliedByAdmin.name')->label('أضيفت بواسطة')->placeholder('-')->toggleable(),
                TextColumn::make('applied_at')->label('تاريخ الإضافة')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('financial_source')
                    ->label('المصدر المالي')
                    ->options([
                        CleaningFinancialPenalty::SOURCE_DEPOSIT => 'رصيد الإيداع',
                        CleaningFinancialPenalty::SOURCE_DEBT => 'الدين',
                    ]),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        CleaningFinancialPenalty::STATUS_ACTIVE => 'فعالة',
                        CleaningFinancialPenalty::STATUS_CLEARED => 'مصفرّة',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                Action::make('open_booking')
                    ->label('فتح الطلب')
                    ->url(fn (CleaningFinancialPenalty $record): string => CleaningBookingResource::getUrl('view', ['record' => $record->cleaning_booking_id])),
            ])
            ->defaultSort('applied_at', 'desc')
            ->emptyStateHeading('لا توجد غرامات مالية')
            ->emptyStateDescription('تظهر هنا الغرامات التي تتم إضافتها من صفحة الطلبات الملغاة.');
    }
}
