<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class EventBookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn (mixed $state): string => $state
            ? __('cleaning_admin.boolean.yes')
            : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('cleaning_admin.event_bookings.sections.booking'))
                    ->schema([
                        TextEntry::make('booking_number')
                            ->label(__('cleaning_admin.event_bookings.fields.booking_number')),
                        TextEntry::make('status')
                            ->label(__('cleaning_admin.event_bookings.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                        TextEntry::make('event_type')
                            ->label(__('cleaning_admin.event_bookings.fields.event_type'))
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                        TextEntry::make('customer.name')
                            ->label(__('cleaning_admin.event_bookings.fields.customer'))
                            ->placeholder('-'),
                        TextEntry::make('guest_count_min')
                            ->label(__('cleaning_admin.event_bookings.fields.guest_count_min'))
                            ->placeholder('-'),
                        TextEntry::make('guest_count_max')
                            ->label(__('cleaning_admin.event_bookings.fields.guest_count_max'))
                            ->placeholder('-'),
                        TextEntry::make('gender_preference')
                            ->label(__('cleaning_admin.event_bookings.fields.gender_preference'))
                            ->placeholder('-'),
                        TextEntry::make('suggested_team_size')
                            ->label(__('cleaning_admin.event_bookings.fields.suggested_team_size'))
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.event_bookings.sections.schedule'))
                    ->schema([
                        TextEntry::make('scheduled_date')
                            ->label(__('cleaning_admin.event_bookings.fields.scheduled_date'))
                            ->date(),
                        TextEntry::make('scheduled_time')
                            ->label(__('cleaning_admin.event_bookings.fields.scheduled_time')),
                        TextEntry::make('total_hours')
                            ->label(__('cleaning_admin.event_bookings.fields.total_hours')),
                        TextEntry::make('terms_accepted')
                            ->label(__('cleaning_admin.event_bookings.fields.terms_accepted'))
                            ->formatStateUsing($yesNo),
                        TextEntry::make('cancelled_at')
                            ->label(__('cleaning_admin.event_bookings.fields.cancelled_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.event_bookings.sections.pricing'))
                    ->schema([
                        TextEntry::make('base_price')
                            ->label(__('cleaning_admin.event_bookings.fields.base_price'))
                            ->money(config('app.currency', 'SYP')),
                        TextEntry::make('travel_fee')
                            ->label(__('cleaning_admin.event_bookings.fields.travel_fee'))
                            ->money(config('app.currency', 'SYP')),
                        TextEntry::make('total_price')
                            ->label(__('cleaning_admin.event_bookings.fields.total_price'))
                            ->money(config('app.currency', 'SYP')),
                    ])
                    ->columns(3),
                Section::make(__('cleaning_admin.event_bookings.sections.policies'))
                    ->schema([
                        TextEntry::make('cancellationPolicy.name')
                            ->label(__('cleaning_admin.event_bookings.fields.cancellation_policy'))
                            ->placeholder('-'),
                        TextEntry::make('billingPolicy.name')
                            ->label(__('cleaning_admin.event_bookings.fields.billing_policy'))
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make(__('cleaning_admin.event_bookings.sections.services'))
                    ->schema([
                        RepeatableEntry::make('services')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')
                                    ->label(__('cleaning_admin.event_bookings.fields.service'))
                                    ->placeholder('-'),
                                TextEntry::make('pivot.quantity')
                                    ->label(__('cleaning_admin.event_bookings.fields.quantity'))
                                    ->placeholder('-'),
                                TextEntry::make('pivot.unit_price')
                                    ->label(__('cleaning_admin.event_bookings.fields.unit_price'))
                                    ->money(config('app.currency', 'SYP')),
                                TextEntry::make('pivot.total_price')
                                    ->label(__('cleaning_admin.event_bookings.fields.service_total_price'))
                                    ->money(config('app.currency', 'SYP')),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn ($record): bool => $record->services->isNotEmpty()),
            ]);
    }
}
