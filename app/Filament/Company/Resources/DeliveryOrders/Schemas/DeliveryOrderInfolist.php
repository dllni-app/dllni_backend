<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Schemas;

use App\Filament\Support\AdminDeliveryLabels;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('delivery_company.orders.sections.pricing'))
                    ->schema([
                        TextEntry::make('order_number')
                            ->label(__('delivery_company.orders.fields.order_number')),
                        TextEntry::make('status')
                            ->label(__('delivery_company.orders.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::orderStatus($state)),
                        TextEntry::make('driver.first_name')
                            ->label(__('delivery_company.orders.fields.driver'))
                            ->placeholder('—'),
                        TextEntry::make('distance_km')
                            ->label(__('delivery_company.orders.fields.distance_km')),
                        TextEntry::make('delivery_fee')
                            ->label(__('delivery_company.orders.fields.delivery_fee'))
                            ->money(fn ($record) => $record->currency ?? config('delivery.pricing.default_currency', 'SYP')),
                        TextEntry::make('stop_reason')
                            ->label(__('delivery_company.orders.fields.stop_reason'))
                            ->placeholder('—')
                            ->visible(fn ($record): bool => filled($record->stop_reason)),
                        TextEntry::make('cancel_reason')
                            ->label(__('delivery_company.orders.fields.cancel_reason'))
                            ->placeholder('—')
                            ->visible(fn ($record): bool => filled($record->cancel_reason)),
                        TextEntry::make('created_at')
                            ->label(__('delivery_company.orders.fields.created_at'))
                            ->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.source'))
                    ->schema([
                        TextEntry::make('source_type')
                            ->label(__('delivery_company.orders.fields.source_type'))
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'restaurant_order' => __('delivery_company.orders.sources.restaurant'),
                                'supermarket_order' => __('delivery_company.orders.sources.supermarket'),
                                default => __('delivery_company.orders.sources.manual'),
                            }),
                        TextEntry::make('source_id')
                            ->label(__('delivery_company.orders.fields.source_id'))
                            ->placeholder('—'),
                        TextEntry::make('merchant_status')
                            ->label(__('delivery_company.orders.fields.merchant_status'))
                            ->formatStateUsing(fn (?string $state, $record): string => AdminDeliveryLabels::merchantStatus($state, $record->source_type))
                            ->placeholder('—'),
                        TextEntry::make('estimated_preparation_minutes')
                            ->label(__('delivery_company.orders.fields.estimated_preparation_minutes'))
                            ->suffix(' '.__('delivery_company.units.minutes'))
                            ->placeholder('—'),
                        TextEntry::make('estimated_ready_at')
                            ->label(__('delivery_company.orders.fields.estimated_ready_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                        TextEntry::make('merchant_ready_at')
                            ->label(__('delivery_company.orders.fields.merchant_ready_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.dispatch'))
                    ->schema([
                        TextEntry::make('dispatch_wave')->label(__('delivery_company.orders.fields.dispatch_wave')),
                        TextEntry::make('search_radius_km')->label(__('delivery_company.orders.fields.search_radius_km'))->suffix(' '.__('delivery_company.units.kilometers'))->placeholder('—'),
                        TextEntry::make('dispatch_phase')
                            ->label(__('delivery_company.orders.fields.dispatch_phase'))
                            ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::dispatchPhase($state))
                            ->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make(__('delivery_company.orders.sections.customer'))
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label(__('delivery_company.orders.fields.customer_name')),
                        TextEntry::make('customer_phone')
                            ->label(__('delivery_company.orders.fields.customer_phone'))
                            ->placeholder('—'),
                        TextEntry::make('customer_notes')
                            ->label(__('delivery_company.orders.fields.customer_notes'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.pickup'))
                    ->schema([
                        TextEntry::make('pickup_address')
                            ->label(__('delivery_company.orders.fields.pickup_address'))
                            ->columnSpanFull(),
                        TextEntry::make('pickup_latitude')
                            ->label(__('delivery_company.orders.fields.pickup_latitude')),
                        TextEntry::make('pickup_longitude')
                            ->label(__('delivery_company.orders.fields.pickup_longitude')),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.dropoff'))
                    ->schema([
                        TextEntry::make('dropoff_address')
                            ->label(__('delivery_company.orders.fields.dropoff_address'))
                            ->columnSpanFull(),
                        TextEntry::make('dropoff_latitude')
                            ->label(__('delivery_company.orders.fields.dropoff_latitude')),
                        TextEntry::make('dropoff_longitude')
                            ->label(__('delivery_company.orders.fields.dropoff_longitude')),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.orders.sections.driver_tracking'))
                    ->schema([
                        TextEntry::make('driver.first_name')
                            ->label(__('delivery_company.orders.fields.driver'))
                            ->placeholder('—'),
                        TextEntry::make('driver.last_seen_at')
                            ->label(__('delivery_company.drivers.fields.last_seen_at'))
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('driver.latestLocation.latitude')
                            ->label(__('delivery_company.drivers.fields.latitude'))
                            ->placeholder('—'),
                        TextEntry::make('driver.latestLocation.longitude')
                            ->label(__('delivery_company.drivers.fields.longitude'))
                            ->placeholder('—'),
                        TextEntry::make('driver.latestLocation.recorded_at')
                            ->label(__('delivery_company.orders.fields.location_recorded_at'))
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('driver_map')
                            ->label(__('delivery_company.orders.fields.driver_map'))
                            ->state(fn ($record): string => __('delivery_company.orders.actions.open_map'))
                            ->url(function ($record): ?string {
                                $location = $record->driver?->latestLocation;
                                if (! $location) {
                                    return null;
                                }

                                return 'https://www.google.com/maps?q='.$location->latitude.','.$location->longitude;
                            })
                            ->openUrlInNewTab(),
                    ])
                    ->columns(2)
                    ->visible(fn ($record): bool => $record->driver_id !== null),
                Section::make(__('delivery_company.orders.sections.lifecycle'))
                    ->schema([
                        TextEntry::make('accepted_at')->label(__('delivery_company.orders.fields.accepted_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('started_at')->label(__('delivery_company.orders.fields.started_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label(__('delivery_company.orders.fields.picked_up_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('delivered_at')->label(__('delivery_company.orders.fields.delivered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('completed_at')->label(__('delivery_company.orders.fields.completed_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label(__('delivery_company.orders.fields.cancelled_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make(__('delivery_company.orders.sections.return'))
                    ->schema([
                        TextEntry::make('delivery_failure_code')
                            ->label(__('delivery_company.orders.fields.delivery_failure_code'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::failureCode($state))
                            ->placeholder('—'),
                        TextEntry::make('delivery_failure_reason')
                            ->label(__('delivery_company.orders.fields.delivery_failure_reason'))
                            ->placeholder('—'),
                        TextEntry::make('delivery_failed_at')
                            ->label(__('delivery_company.orders.fields.delivery_failed_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                        TextEntry::make('returned_to_merchant_at')
                            ->label(__('delivery_company.orders.fields.returned_to_merchant_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn ($record): bool => filled($record->delivery_failure_code)
                        || filled($record->delivery_failure_reason)
                        || $record->delivery_failed_at !== null
                        || $record->returned_to_merchant_at !== null),
                Section::make(__('delivery_company.orders.sections.timeline'))
                    ->schema([
                        RepeatableEntry::make('events')
                            ->label('')
                            ->schema([
                                TextEntry::make('to_status')
                                    ->label(__('delivery_company.orders.fields.status'))
                                    ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::orderStatus($state)),
                                TextEntry::make('note')
                                    ->label(__('delivery_company.orders.fields.note'))
                                    ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::note($state))
                                    ->placeholder('—'),
                                TextEntry::make('created_at')
                                    ->label(__('delivery_company.orders.fields.created_at'))
                                    ->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->events()->exists()),
                Section::make(__('delivery_company.orders.sections.attempts'))
                    ->schema([
                        RepeatableEntry::make('assignmentAttempts')
                            ->label('')
                            ->schema([
                                TextEntry::make('driver.first_name')
                                    ->label(__('delivery_company.orders.fields.driver'))
                                    ->placeholder('—'),
                                TextEntry::make('status')
                                    ->label(__('delivery_company.orders.fields.status'))
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::attemptStatus($state)),
                                TextEntry::make('attempt_no')->label(__('delivery_company.orders.fields.attempt_no')),
                                TextEntry::make('distance_to_pickup_km')->label(__('delivery_company.orders.fields.distance_to_pickup_km')),
                                TextEntry::make('offered_at')->label(__('delivery_company.orders.fields.offered_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                                TextEntry::make('expires_at')->label(__('delivery_company.orders.fields.expires_at'))->dateTime('Y-m-d H:i')->placeholder('—'),
                                TextEntry::make('reject_reason')
                                    ->label(__('delivery_company.orders.fields.reject_reason'))
                                    ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::note($state))
                                    ->placeholder('—'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->assignmentAttempts()->exists()),
            ]);
    }
}
