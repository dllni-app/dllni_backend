<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders\Schemas;

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
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
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
                Section::make(__('delivery_company.orders.sections.timeline'))
                    ->schema([
                        RepeatableEntry::make('events')
                            ->label('')
                            ->schema([
                                TextEntry::make('to_status')
                                    ->label(__('delivery_company.orders.fields.status'))
                                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                                TextEntry::make('note')->placeholder('—'),
                                TextEntry::make('created_at')->dateTime('Y-m-d H:i'),
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
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => $state
                                        ? __('delivery_company.orders.enums.attempt_status.'.$state)
                                        : '—'),
                                TextEntry::make('attempt_no')->label('#'),
                                TextEntry::make('distance_to_pickup_km')->label(__('delivery_company.orders.fields.distance_to_pickup_km')),
                                TextEntry::make('offered_at')->dateTime('Y-m-d H:i')->placeholder('—'),
                                TextEntry::make('expires_at')->dateTime('Y-m-d H:i')->placeholder('—'),
                                TextEntry::make('reject_reason')->placeholder('—'),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn ($record): bool => $record->assignmentAttempts()->exists()),
            ]);
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null) {
            return '—';
        }

        $key = 'delivery_company.orders.enums.status.'.$status;

        return __($key) === $key ? $status : __($key);
    }
}
