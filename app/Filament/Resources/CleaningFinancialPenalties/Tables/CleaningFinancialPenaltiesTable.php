<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Tables;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\CleaningWorkers\CleaningWorkerResource;
use App\Models\CleaningFinancialPenalty;
use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CleaningFinancialPenaltiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('الرقم')->sortable(),
                TextColumn::make('booking.booking_number')->label('الطلب')->searchable()->sortable(),
                TextColumn::make('worker.first_name')->label('العامل')->searchable()->sortable(),
                TextColumn::make('amount')->label('القيمة')->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('financial_source')
                    ->label('المصدر المالي')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::SOURCE_DEPOSIT ? 'الإيداع' : 'الدين')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::SOURCE_DEPOSIT ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::STATUS_ACTIVE ? 'نشطة' : 'مصفرة')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::STATUS_ACTIVE ? 'danger' : 'success'),
                TextColumn::make('cancellation_offset_minutes')
                    ->label('توقيت الإلغاء')
                    ->formatStateUsing(fn ($state): string => self::timingLabel($state))
                    ->toggleable(),
                TextColumn::make('appliedByAdmin.name')->label('أضافها')->placeholder('-')->toggleable(),
                TextColumn::make('applied_at')->label('تاريخ الإضافة')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('cleared_at')->label('تاريخ التصفير')->dateTime('Y-m-d H:i')->placeholder('-')->toggleable(),
                TextColumn::make('notes')->label('الملاحظات')->limit(50)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('worker_id')->label('العامل')->options(fn (): array => Worker::query()->orderBy('first_name')->pluck('first_name', 'id')->all())->searchable()->preload(),
                SelectFilter::make('financial_source')->label('المصدر المالي')->options([
                    CleaningFinancialPenalty::SOURCE_DEPOSIT => 'الإيداع',
                    CleaningFinancialPenalty::SOURCE_DEBT => 'الدين',
                ]),
                SelectFilter::make('status')->label('الحالة')->options([
                    CleaningFinancialPenalty::STATUS_ACTIVE => 'نشطة',
                    CleaningFinancialPenalty::STATUS_CLEARED => 'مصفرة',
                ]),
                SelectFilter::make('cancellation_timing')
                    ->label('توقيت الإلغاء')
                    ->options(['before' => 'قبل الموعد', 'after' => 'بعد الموعد', 'at' => 'في الموعد'])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'before' => $query->where('cancellation_offset_minutes', '>', 0),
                            'after' => $query->where('cancellation_offset_minutes', '<', 0),
                            'at' => $query->where('cancellation_offset_minutes', 0),
                            default => $query,
                        };
                    }),
                Filter::make('applied_at')
                    ->form([
                        DatePicker::make('from')->label('من')->native(false),
                        DatePicker::make('to')->label('إلى')->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('applied_at', '>=', $date))
                        ->when($data['to'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('applied_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('open_booking')
                    ->label('فتح الطلب')
                    ->icon('heroicon-o-calendar-days')
                    ->url(fn (CleaningFinancialPenalty $record): string => CleaningBookingResource::getUrl('view', ['record' => $record->cleaning_booking_id])),
                Action::make('open_worker')
                    ->label('فتح العامل')
                    ->icon('heroicon-o-user')
                    ->url(fn (CleaningFinancialPenalty $record): string => CleaningWorkerResource::getUrl('view', ['record' => $record->worker_id])),
            ])
            ->defaultSort('applied_at', 'desc');
    }

    private static function timingLabel(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        $minutes = (int) $value;

        return $minutes > 0
            ? "قبل الموعد بـ {$minutes} دقيقة"
            : ($minutes < 0 ? 'بعد الموعد بـ '.abs($minutes).' دقيقة' : 'في موعد العمل');
    }
}
