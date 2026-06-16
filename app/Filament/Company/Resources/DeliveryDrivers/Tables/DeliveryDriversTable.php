<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Tables;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DriverManagementService;

final class DeliveryDriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->label(__('delivery_company.drivers.fields.first_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('delivery_company.drivers.fields.phone'))
                    ->placeholder('—'),
                TextColumn::make('availability_status')
                    ->label(__('delivery_company.drivers.fields.availability_status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? __('delivery_company.drivers.enums.availability.'.$state)
                        : '—'),
                IconColumn::make('is_active')
                    ->label(__('delivery_company.drivers.fields.is_active'))
                    ->boolean(),
                IconColumn::make('is_suspended')
                    ->label(__('delivery_company.drivers.fields.is_suspended'))
                    ->boolean(),
                TextColumn::make('trust_score')
                    ->label(__('delivery_company.drivers.fields.trust_score'))
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label(__('delivery_company.drivers.fields.last_seen_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('delivery_company.drivers.fields.is_active')),
                TernaryFilter::make('is_suspended')->label(__('delivery_company.drivers.fields.is_suspended')),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->recordActions([
                ViewAction::make(),
                Action::make('suspend')
                    ->label(__('delivery_company.drivers.actions.suspend'))
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (DeliveryDriver $record): bool => auth()->user()?->can('suspend', $record) === true
                        && ! $record->is_suspended
                        && $record->is_active)
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label(__('delivery_company.drivers.actions.suspend_reason'))
                            ->default(DeliverySuspensionReason::Manual->value)
                            ->required()
                            ->maxLength(500),
                        DateTimePicker::make('suspended_until')
                            ->label(__('delivery_company.drivers.actions.suspend_until')),
                    ])
                    ->action(function (DeliveryDriver $record, array $data): void {
                        try {
                            app(DriverManagementService::class)->suspend(
                                $record,
                                (string) $data['reason'],
                                isset($data['suspended_until']) ? $data['suspended_until'] : null,
                            );
                            Notification::make()
                                ->title(__('delivery_company.drivers.actions.suspend'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('unsuspend')
                    ->label(__('delivery_company.drivers.actions.unsuspend'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DeliveryDriver $record): bool => auth()->user()?->can('unsuspend', $record) === true
                        && $record->is_suspended
                        && $record->suspension_reason !== DeliverySuspensionReason::Financial->value)
                    ->requiresConfirmation()
                    ->action(function (DeliveryDriver $record): void {
                        try {
                            app(DriverManagementService::class)->unsuspend($record);
                            Notification::make()
                                ->title(__('delivery_company.drivers.actions.unsuspend'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('activate')
                    ->label(__('delivery_company.drivers.actions.activate'))
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (DeliveryDriver $record): bool => auth()->user()?->can('update', $record) === true && ! $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (DeliveryDriver $record): void {
                        try {
                            app(DriverManagementService::class)->activate($record);
                            Notification::make()
                                ->title(__('delivery_company.drivers.actions.activate'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('deactivate')
                    ->label(__('delivery_company.drivers.actions.deactivate'))
                    ->icon('heroicon-o-pause')
                    ->color('warning')
                    ->visible(fn (DeliveryDriver $record): bool => auth()->user()?->can('update', $record) === true && $record->is_active)
                    ->requiresConfirmation()
                    ->action(function (DeliveryDriver $record): void {
                        try {
                            app(DriverManagementService::class)->deactivate($record);
                            Notification::make()
                                ->title(__('delivery_company.drivers.actions.deactivate'))
                                ->success()
                                ->send();
                        } catch (InvalidArgumentException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('first_name');
    }
}
