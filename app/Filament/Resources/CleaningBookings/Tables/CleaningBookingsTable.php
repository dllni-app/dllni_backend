<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Tables;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')
                    ->label(__('cleaning_admin.booking.fields.booking_number'))
                    ->description(__('cleaning_admin.column_descriptions.booking_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('cleaning_admin.booking.fields.status'))
                    ->description(__('cleaning_admin.column_descriptions.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('customer.name')
                    ->label(__('cleaning_admin.booking.fields.customer'))
                    ->description(__('cleaning_admin.column_descriptions.customer'))
                    ->searchable(),
                TextColumn::make('worker.first_name')
                    ->label(__('cleaning_admin.booking.fields.worker'))
                    ->description(__('cleaning_admin.column_descriptions.worker'))
                    ->placeholder('—'),
                TextColumn::make('scheduled_date')
                    ->label(__('cleaning_admin.booking.fields.scheduled_date'))
                    ->description(__('cleaning_admin.column_descriptions.scheduled_date'))
                    ->date()
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label(__('cleaning_admin.booking.fields.scheduled_time'))
                    ->description(__('cleaning_admin.column_descriptions.scheduled_time')),
                TextColumn::make('total_price')
                    ->label(__('cleaning_admin.booking.fields.total_price'))
                    ->description(__('cleaning_admin.column_descriptions.total_price'))
                    ->money('SAR')
                    ->sortable(),
                TextColumn::make('disputes_count')
                    ->counts('disputes')
                    ->label(__('cleaning_admin.booking.fields.disputes_count'))
                    ->description(__('cleaning_admin.column_descriptions.disputes_count')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                Filter::make('has_dispute')
                    ->label('يحتوي على نزاع')
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('scheduled_today')
                    ->label('اليوم فقط')
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
            ])
            ->recordActions([
                Action::make('assign_worker')
                    ->label('تعيين عامل')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (CleaningBooking $record) => $record->status === CleaningBookingStatus::Pending)
                    ->form([
                        Select::make('worker_id')
                            ->label('العامل')
                            ->options(Worker::query()->where('is_active', true)->get()->mapWithKeys(fn ($w) => [$w->id => $w->first_name.' ('.$w->user?->phone.')']))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $record->update(['worker_id' => $data['worker_id'], 'status' => CleaningBookingStatus::WorkerAssigned]);
                        Notification::make()->title('تم تعيين العامل')->success()->send();
                    }),
                Action::make('cancel')
                    ->label('إلغاء')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->visible(fn (CleaningBooking $record) => ! in_array($record->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true))
                    ->requiresConfirmation()
                    ->modalHeading('إلغاء الحجز')
                    ->action(function (CleaningBooking $record): void {
                        $record->update(['status' => CleaningBookingStatus::Cancelled, 'cancelled_at' => now()]);
                        Notification::make()->title('تم إلغاء الحجز')->success()->send();
                    }),
                ViewAction::make(),
            ]);
    }
}
