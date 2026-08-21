<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties\Tables;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\CleaningFinancialPenalty;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use Modules\Cleaning\Services\CleaningCancellationFinancialPenaltyService;

final class CleaningFinancialPenaltiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'booking',
                'worker.user',
                'customer',
                'appliedByAdmin',
                'reviewedByAdmin',
                'cancelledByAdmin',
            ]))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('booking.booking_number')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('penalized_role')
                    ->label('الطرف الملغِي')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::ROLE_CUSTOMER ? 'المستخدم' : 'العامل')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::ROLE_CUSTOMER ? 'info' : 'warning'),
                TextColumn::make('penalized_party')
                    ->label('صاحب الغرامة')
                    ->state(fn (CleaningFinancialPenalty $record): string => $record->penalized_role === CleaningFinancialPenalty::ROLE_CUSTOMER
                        ? (string) ($record->customer?->name ?? '-')
                        : (string) ($record->worker?->user?->name ?? '-')),
                TextColumn::make('amount')
                    ->label('قيمة الغرامة')
                    ->money('SYP')
                    ->sortable(),
                TextColumn::make('review_status')
                    ->label('المراجعة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::REVIEW_REVIEWED ? 'تمت مراجعتها' : 'تحتاج مراجعة')
                    ->color(fn (string $state): string => $state === CleaningFinancialPenalty::REVIEW_REVIEWED ? 'success' : 'warning'),
                TextColumn::make('status')
                    ->label('حالة الغرامة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CleaningFinancialPenalty::STATUS_CANCELLED => 'ملغاة',
                        CleaningFinancialPenalty::STATUS_CLEARED => 'مصفرّة',
                        default => 'فعالة',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        CleaningFinancialPenalty::STATUS_CANCELLED => 'gray',
                        CleaningFinancialPenalty::STATUS_CLEARED => 'success',
                        default => 'danger',
                    }),
                TextColumn::make('financial_source')
                    ->label('المصدر المالي')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        CleaningFinancialPenalty::SOURCE_DEPOSIT => 'رصيد الإيداع',
                        CleaningFinancialPenalty::SOURCE_DEBT => 'الدين',
                        CleaningFinancialPenalty::SOURCE_MIXED => 'إيداع + دين',
                        CleaningFinancialPenalty::SOURCE_CUSTOMER_FEE => 'غرامة مستخدم',
                        default => $state,
                    })
                    ->toggleable(),
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
                TextColumn::make('reviewedByAdmin.name')->label('راجعها')->placeholder('-')->toggleable(),
                TextColumn::make('applied_at')->label('تاريخ التسجيل')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('penalized_role')
                    ->label('الطرف الملغِي')
                    ->options([
                        CleaningFinancialPenalty::ROLE_CUSTOMER => 'المستخدم',
                        CleaningFinancialPenalty::ROLE_WORKER => 'العامل',
                    ]),
                SelectFilter::make('review_status')
                    ->label('المراجعة')
                    ->options([
                        CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW => 'تحتاج مراجعة',
                        CleaningFinancialPenalty::REVIEW_REVIEWED => 'تمت مراجعتها',
                    ]),
                SelectFilter::make('status')
                    ->label('حالة الغرامة')
                    ->options([
                        CleaningFinancialPenalty::STATUS_ACTIVE => 'فعالة',
                        CleaningFinancialPenalty::STATUS_CLEARED => 'مصفرّة',
                        CleaningFinancialPenalty::STATUS_CANCELLED => 'ملغاة',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                Action::make('mark_reviewed')
                    ->label('تمت المراجعة')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CleaningFinancialPenalty $record): bool => ! $record->isCancelled() && $record->needsReview())
                    ->action(function (CleaningFinancialPenalty $record): void {
                        try {
                            app(CleaningCancellationFinancialPenaltyService::class)->markReviewed($record, auth()->id());
                            Notification::make()->title('تم تحديث الغرامة إلى تمت مراجعتها')->success()->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title('تعذر تحديث الغرامة')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('mark_needs_review')
                    ->label('تحتاج مراجعة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (CleaningFinancialPenalty $record): bool => ! $record->isCancelled() && ! $record->needsReview())
                    ->action(function (CleaningFinancialPenalty $record): void {
                        try {
                            app(CleaningCancellationFinancialPenaltyService::class)->markNeedsReview($record);
                            Notification::make()->title('تمت إعادة الغرامة إلى تحتاج مراجعة')->success()->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title('تعذر تحديث الغرامة')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('cancel_penalty')
                    ->label('إلغاء الغرامة')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('إلغاء الغرامة المالية')
                    ->modalDescription('سيتم إلغاء الغرامة وعكس أثرها المالي إن كان قد تم تطبيقه على العامل.')
                    ->form([
                        Textarea::make('reason')
                            ->label('سبب إلغاء الغرامة')
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->visible(fn (CleaningFinancialPenalty $record): bool => ! $record->isCancelled())
                    ->action(function (CleaningFinancialPenalty $record, array $data): void {
                        app(CleaningCancellationFinancialPenaltyService::class)->cancelPenalty(
                            penalty: $record,
                            adminId: auth()->id(),
                            reason: $data['reason'] ?? null,
                        );

                        Notification::make()->title('تم إلغاء الغرامة وعكس أثرها المالي')->success()->send();
                    }),
                Action::make('open_booking')
                    ->label('فتح الطلب')
                    ->url(fn (CleaningFinancialPenalty $record): string => CleaningBookingResource::getUrl('view', ['record' => $record->cleaning_booking_id])),
            ])
            ->defaultSort('applied_at', 'desc')
            ->emptyStateHeading('لا توجد غرامات مالية')
            ->emptyStateDescription('تظهر هنا تلقائياً غرامات الإلغاء المسجلة على المستخدم أو العامل.');
    }
}
