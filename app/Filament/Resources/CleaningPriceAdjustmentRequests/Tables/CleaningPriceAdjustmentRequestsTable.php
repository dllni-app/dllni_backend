<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningPriceAdjustmentRequests\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;
use Modules\Cleaning\Services\CleaningBookingPriceAdjustmentService;

final class CleaningPriceAdjustmentRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('booking.booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('booking.customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('worker.first_name')
                    ->label('العامل')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('booking.status')
                    ->label('حالة الحجز')
                    ->badge()
                    ->color(fn ($state): string => self::bookingStatusColor($state))
                    ->formatStateUsing(fn ($state): string => self::bookingStatusLabel($state))
                    ->placeholder('-'),
                TextColumn::make('old_total_price')
                    ->label('السعر الحالي')
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->sortable(),
                TextColumn::make('proposed_total_price')
                    ->label('السعر المقترح')
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->sortable(),
                TextColumn::make('price_difference')
                    ->label('الفرق')
                    ->getStateUsing(fn (CleaningBookingPriceAdjustmentRequest $record): float => (float) $record->proposed_total_price - (float) $record->old_total_price)
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->color(fn ($state): string => (float) $state >= 0 ? 'warning' : 'success'),
                TextColumn::make('reason')
                    ->label('سبب العامل')
                    ->limit(50)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('حالة الطلب')
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                TextColumn::make('admin_final_total_price')
                    ->label('السعر النهائي')
                    ->formatStateUsing(fn ($state): string => $state !== null ? self::money($state) : '-')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('reviewedBy.name')
                    ->label('راجعه')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('تاريخ الطلب')
                    ->since()
                    ->sortable(),
                TextColumn::make('reviewed_at')
                    ->label('تاريخ المراجعة')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'booking.customer',
                    'worker.user',
                    'reviewedBy',
                ]))
            ->filters([
                SelectFilter::make('status')
                    ->label('حالة طلب التعديل')
                    ->options(collect(CleaningPriceAdjustmentRequestStatus::cases())->mapWithKeys(
                        fn (CleaningPriceAdjustmentRequestStatus $status): array => [$status->value => $status->label()]
                    )->all()),
                SelectFilter::make('booking_status')
                    ->label('حالة الحجز')
                    ->relationship('booking', 'status')
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(
                        fn (CleaningBookingStatus $status): array => [$status->value => $status->label()]
                    )->all()),
                Filter::make('pending')
                    ->label('بانتظار المراجعة')
                    ->query(fn (Builder $query): Builder => $query->where('status', CleaningPriceAdjustmentRequestStatus::Pending->value)),
                Filter::make('created_today')
                    ->label('طلبات اليوم')
                    ->query(fn (Builder $query): Builder => $query->whereDate('created_at', today())),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('الموافقة وتحديث السعر')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CleaningBookingPriceAdjustmentRequest $record): bool => self::isPending($record))
                    ->modalHeading('الموافقة على تعديل السعر')
                    ->modalDescription('سيتم تحديث السعر الإجمالي للحجز وتصبح بداية العمل متاحة للعامل بعد الموافقة.')
                    ->form([
                        TextInput::make('admin_final_total_price')
                            ->label('السعر النهائي المعتمد')
                            ->numeric()
                            ->minValue(1)
                            ->default(fn (CleaningBookingPriceAdjustmentRequest $record): float => (float) $record->proposed_total_price)
                            ->required(),
                        Textarea::make('admin_note')
                            ->label('ملاحظة الإدارة')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (CleaningBookingPriceAdjustmentRequest $record, array $data): void {
                        app(CleaningBookingPriceAdjustmentService::class)->approve(
                            $record,
                            $data['admin_final_total_price'],
                            filled($data['admin_note'] ?? null) ? (string) $data['admin_note'] : null,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('تمت الموافقة على تعديل السعر')
                            ->body('تم تحديث سعر الحجز وأصبح بإمكان العامل متابعة بدء العمل.')
                            ->success()
                            ->send();
                    }),
                Action::make('reject')
                    ->label('رفض الطلب')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CleaningBookingPriceAdjustmentRequest $record): bool => self::isPending($record))
                    ->requiresConfirmation()
                    ->modalHeading('رفض طلب تعديل السعر')
                    ->modalDescription('سيتم رفض الطلب وإتاحة بدء العمل بالسعر الحالي بعد التواصل مع الأطراف.')
                    ->form([
                        Textarea::make('admin_note')
                            ->label('سبب الرفض / ملاحظة الإدارة')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (CleaningBookingPriceAdjustmentRequest $record, array $data): void {
                        app(CleaningBookingPriceAdjustmentService::class)->reject(
                            $record,
                            filled($data['admin_note'] ?? null) ? (string) $data['admin_note'] : null,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('تم رفض طلب تعديل السعر')
                            ->success()
                            ->send();
                    }),
                Action::make('resolve_without_change')
                    ->label('تم التواصل بدون تعديل')
                    ->icon('heroicon-o-phone')
                    ->color('gray')
                    ->visible(fn (CleaningBookingPriceAdjustmentRequest $record): bool => self::isPending($record))
                    ->modalHeading('إغلاق الطلب بدون تعديل السعر')
                    ->modalDescription('استخدم هذا الإجراء عندما يتم التواصل مع العامل والعميل ولا يوجد تغيير على السعر.')
                    ->form([
                        Textarea::make('admin_note')
                            ->label('ملاحظة الإدارة')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (CleaningBookingPriceAdjustmentRequest $record, array $data): void {
                        app(CleaningBookingPriceAdjustmentService::class)->resolveWithoutChange(
                            $record,
                            filled($data['admin_note'] ?? null) ? (string) $data['admin_note'] : null,
                            auth()->user(),
                        );

                        Notification::make()
                            ->title('تم إغلاق طلب تعديل السعر بدون تغيير')
                            ->success()
                            ->send();
                    }),
                ViewAction::make()->label('عرض'),
            ]);
    }

    private static function isPending(CleaningBookingPriceAdjustmentRequest $record): bool
    {
        $status = $record->status instanceof CleaningPriceAdjustmentRequestStatus
            ? $record->status
            : CleaningPriceAdjustmentRequestStatus::tryFrom((string) $record->status);

        return $status === CleaningPriceAdjustmentRequestStatus::Pending;
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

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2).' '.config('app.currency', 'SYP');
    }
}
