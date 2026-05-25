<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Tables;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
                    ->formatStateUsing(fn ($state) => $state?->label()),
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
                    ->label('Event type')
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
                    ->money(config('app.currency', 'SYP'))
                    ->sortable(),
                TextColumn::make('is_pricing_final')
                    ->label('Pricing')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Final' : 'Provisional'),
                TextColumn::make('disputes_count')
                    ->counts('disputes')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.disputes_count'),
                        __('cleaning_admin.column_descriptions.disputes_count'),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Filter::make('has_dispute')
                    ->label('Has dispute')
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('scheduled_today')
                    ->label('Scheduled today')
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
                SelectFilter::make('property_type')
                    ->label('Property type')
                    ->options([
                        UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE => 'Event assistance',
                        'apartment' => 'Apartment',
                        'villa' => 'Villa',
                        'house' => 'House',
                        'office' => 'Office',
                        'studio' => 'Studio',
                    ]),
            ])
            ->recordActions([
                Action::make('assign_worker')
                    ->label('Assign worker')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (CleaningBooking $record) => $record->status === CleaningBookingStatus::Pending && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->form([
                        Select::make('worker_id')
                            ->label('Worker')
                            ->options(Worker::query()->where('is_active', true)->get()->mapWithKeys(fn ($w) => [$w->id => $w->first_name.' ('.$w->user?->phone.')']))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $record->update([
                            'worker_id' => $data['worker_id'],
                            'status' => CleaningBookingStatus::WorkerAssigned,
                            'cancelled_at' => null,
                            'cancellation_reason' => null,
                        ]);
                        Notification::make()->title('Worker assigned')->success()->send();
                    }),
                Action::make('start_work')
                    ->label('Start work')
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (CleaningBooking $record): bool => $record->status === CleaningBookingStatus::WorkerAssigned && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->action(function (CleaningBooking $record): void {
                        $record->update([
                            'status' => CleaningBookingStatus::InProgress,
                            'work_started_at' => $record->work_started_at ?? now(),
                        ]);

                        Notification::make()->title('Work started')->success()->send();
                    }),
                Action::make('complete')
                    ->label('Complete')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CleaningBooking $record): bool => $record->status === CleaningBookingStatus::InProgress && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->action(function (CleaningBooking $record): void {
                        $record->update([
                            'status' => CleaningBookingStatus::Completed,
                            'work_finished_at' => now(),
                        ]);

                        Notification::make()->title('Booking completed')->success()->send();
                    }),
                Action::make('cancel')
                    ->label('Cancel')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (CleaningBooking $record) => ! in_array($record->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true) && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE)
                    ->requiresConfirmation()
                    ->modalHeading('Cancel booking')
                    ->action(function (CleaningBooking $record): void {
                        $record->update(['status' => CleaningBookingStatus::Cancelled, 'cancelled_at' => now()]);
                        Notification::make()->title('Booking cancelled')->success()->send();
                    }),
                EditAction::make()
                    ->visible(fn (CleaningBooking $record): bool => $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE),
                ViewAction::make(),
            ]);
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
