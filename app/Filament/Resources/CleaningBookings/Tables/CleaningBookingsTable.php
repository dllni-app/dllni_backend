<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Tables;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.booking_number'),
                        __('cleaning_admin.column_descriptions.booking_number'),
                    ))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.status'),
                        __('cleaning_admin.column_descriptions.status'),
                    ))
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                TextColumn::make('customer.name')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.customer'),
                        __('cleaning_admin.column_descriptions.customer'),
                    ))
                    ->searchable(),
                TextColumn::make('worker.first_name')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.worker'),
                        __('cleaning_admin.column_descriptions.worker'),
                    ))
                    ->placeholder('-'),
                TextColumn::make('number_of_workers')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.number_of_workers'),
                        __('cleaning_admin.column_descriptions.number_of_workers'),
                    ))
                    ->sortable(),
                TextColumn::make('property_details.event_type')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.event_type'),
                        __('cleaning_admin.column_descriptions.event_type'),
                    ))
                    ->formatStateUsing(fn (?string $state): string => self::translatedValue('cleaning_admin.enums.event_type.', $state))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('scheduled_date')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.scheduled_date'),
                        __('cleaning_admin.column_descriptions.scheduled_date'),
                    ))
                    ->date()
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.scheduled_time'),
                        __('cleaning_admin.column_descriptions.scheduled_time'),
                    )),
                TextColumn::make('total_price')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.total_price'),
                        __('cleaning_admin.column_descriptions.total_price'),
                    ))
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->tooltip(fn (CleaningBooking $record): string => self::priceFormula($record))
                    ->extraAttributes(fn (CleaningBooking $record): array => [
                        'title' => self::priceFormula($record),
                    ])
                    ->sortable(),
                TextColumn::make('is_pricing_final')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.pricing_status'),
                        __('cleaning_admin.column_descriptions.pricing_status'),
                    ))
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state): string => $state ? __('cleaning_admin.booking.pricing.final') : __('cleaning_admin.booking.pricing.provisional')),
                TextColumn::make('disputes_count')
                    ->counts('disputes')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.disputes_count'),
                        __('cleaning_admin.column_descriptions.disputes_count'),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cleaning_admin.booking.filters.status'))
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(fn (CleaningBookingStatus $case): array => [$case->value => $case->label()])->all()),
                Filter::make('has_dispute')
                    ->label(__('cleaning_admin.booking.filters.has_dispute'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('scheduled_today')
                    ->label(__('cleaning_admin.booking.filters.scheduled_today'))
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
                SelectFilter::make('property_type')
                    ->label(__('cleaning_admin.booking.filters.property_type'))
                    ->options([
                        UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE => __('cleaning_admin.booking.property_types.event_assistance'),
                        'apartment' => __('cleaning_admin.booking.property_types.apartment'),
                        'villa' => __('cleaning_admin.booking.property_types.villa'),
                        'house' => __('cleaning_admin.booking.property_types.house'),
                        'office' => __('cleaning_admin.booking.property_types.office'),
                        'studio' => __('cleaning_admin.booking.property_types.studio'),
                    ]),
            ])
            ->recordActions([
                Action::make('assign_worker')
                    ->label(__('cleaning_admin.booking.actions.assign_worker'))
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (CleaningBooking $record) => $record->status === CleaningBookingStatus::Pending && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->modalHeading(__('cleaning_admin.booking.actions.assign_worker'))
                    ->form([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.booking.actions.worker'))
                            ->options(
                                Worker::query()
                                    ->with('user')
                                    ->where('is_active', true)
                                    ->get()
                                    ->mapWithKeys(fn (Worker $worker): array => [
                                        $worker->id => trim(($worker->first_name ?: $worker->user?->name ?: '-').' ('.($worker->user?->phone ?: '-').')'),
                                    ])
                            )
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $record->forceFill([
                            'worker_id' => $data['worker_id'],
                            'status' => CleaningBookingStatus::WorkerAssigned,
                            'cancelled_at' => null,
                            'cancellation_reason' => null,
                        ])->save();

                        Notification::make()
                            ->title(__('cleaning_admin.booking.actions.worker_assigned'))
                            ->success()
                            ->send();
                    }),
                Action::make('start_work')
                    ->label(__('cleaning_admin.booking.actions.start_work'))
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (CleaningBooking $record): bool => $record->status === CleaningBookingStatus::WorkerAssigned && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->action(function (CleaningBooking $record): void {
                        $record->update([
                            'status' => CleaningBookingStatus::InProgress,
                            'work_started_at' => $record->work_started_at ?? now(),
                        ]);

                        Notification::make()->title(__('cleaning_admin.booking.actions.work_started'))->success()->send();
                    }),
                Action::make('complete')
                    ->label(__('cleaning_admin.booking.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CleaningBooking $record): bool => $record->status === CleaningBookingStatus::InProgress && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->action(function (CleaningBooking $record): void {
                        $record->update([
                            'status' => CleaningBookingStatus::Completed,
                            'work_finished_at' => now(),
                        ]);

                        Notification::make()->title(__('cleaning_admin.booking.actions.booking_completed'))->success()->send();
                    }),
                Action::make('cancel')
                    ->label(__('cleaning_admin.booking.actions.cancel'))
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (CleaningBooking $record) => ! in_array($record->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true) && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->modalHeading(__('cleaning_admin.booking.actions.cancel_booking'))
                    ->action(function (CleaningBooking $record): void {
                        $record->update(['status' => CleaningBookingStatus::Cancelled, 'cancelled_at' => now()]);

                        Notification::make()->title(__('cleaning_admin.booking.actions.booking_cancelled'))->success()->send();
                    }),
                EditAction::make()
                    ->label(__('filament-actions::edit.single.label'))
                    ->visible(fn (CleaningBooking $record): bool => $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE),
                ViewAction::make()
                    ->label(__('filament-actions::view.single.label')),
            ]);
    }

    private static function statusLabel(CleaningBookingStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->label() ?? '-';
    }

    private static function statusColor(CleaningBookingStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return match ($status) {
            CleaningBookingStatus::Pending => 'warning',
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification => 'info',
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::TimeExtensionRequested => 'primary',
            CleaningBookingStatus::AwaitingCustomerCompletion => 'gray',
            CleaningBookingStatus::Completed => 'success',
            CleaningBookingStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeStatus(CleaningBookingStatus|string|null $status): ?CleaningBookingStatus
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

    private static function priceFormula(CleaningBooking $record): string
    {
        return __('cleaning_admin.booking.pricing.formula', [
            'base' => self::money($record->base_price),
            'addons' => self::money($record->addons_total),
            'travel' => self::money($record->travel_fee),
            'cancellation' => self::money($record->cancellation_fee),
            'total' => self::money($record->total_price),
        ]);
    }

    private static function translatedValue(string $prefix, ?string $value): string
    {
        if (! $value) {
            return '-';
        }

        $key = $prefix.$value;

        return __($key) === $key ? $value : __($key);
    }

    private static function headerLabel(string $label, string $description): HtmlString
    {
        return new HtmlString(
            '<span style="display:flex;flex-direction:column;line-height:1.2;">'
                . '<span style="display:block;font-weight:600;color:inherit;">'.e($label).'</span>'
                . '<span style="display:block;margin-top:2px;font-size:11px;font-weight:400;color:#9ca3af;">'.e($description).'</span>'
                . '</span>',
        );
    }
}
