<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryDriverInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn (bool $state): string => $state
            ? __('cleaning_admin.boolean.yes')
            : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('delivery_company.drivers.sections.profile'))
                    ->schema([
                        TextEntry::make('first_name')->label(__('delivery_company.drivers.fields.first_name')),
                        TextEntry::make('phone')->label(__('delivery_company.drivers.fields.phone'))->placeholder('—'),
                        TextEntry::make('user.email')->label(__('delivery_company.drivers.fields.user')),
                        TextEntry::make('vehicle_type')->label(__('delivery_company.drivers.fields.vehicle_type'))->placeholder('—'),
                        TextEntry::make('plate_number')->label(__('delivery_company.drivers.fields.plate_number'))->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.drivers.sections.status'))
                    ->schema([
                        TextEntry::make('availability_status')
                            ->label(__('delivery_company.drivers.fields.availability_status'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? __('delivery_company.drivers.enums.availability.'.$state)
                                : '—'),
                        TextEntry::make('is_active')
                            ->label(__('delivery_company.drivers.fields.is_active'))
                            ->formatStateUsing(fn ($state): string => $yesNo((bool) $state)),
                        TextEntry::make('is_suspended')
                            ->label(__('delivery_company.drivers.fields.is_suspended'))
                            ->formatStateUsing(fn ($state): string => $yesNo((bool) $state)),
                        TextEntry::make('suspension_reason')
                            ->label(__('delivery_company.drivers.fields.suspension_reason'))
                            ->placeholder('—')
                            ->visible(fn ($record): bool => (bool) $record->is_suspended),
                        TextEntry::make('suspended_until')
                            ->label(__('delivery_company.drivers.fields.suspended_until'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—')
                            ->visible(fn ($record): bool => (bool) $record->suspended_until),
                        TextEntry::make('last_seen_at')
                            ->label(__('delivery_company.drivers.fields.last_seen_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.drivers.sections.trust'))
                    ->schema([
                        TextEntry::make('trust_score')->label(__('delivery_company.drivers.fields.trust_score')),
                        TextEntry::make('open_disputes_count')->label(__('delivery_company.drivers.fields.open_disputes_count')),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.drivers.sections.location'))
                    ->schema([
                        TextEntry::make('latest_latitude')
                            ->label(__('delivery_company.drivers.fields.latitude'))
                            ->state(fn ($record) => $record->locations()->latest('recorded_at')->value('latitude'))
                            ->placeholder('—'),
                        TextEntry::make('latest_longitude')
                            ->label(__('delivery_company.drivers.fields.longitude'))
                            ->state(fn ($record) => $record->locations()->latest('recorded_at')->value('longitude'))
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Trust history')
                    ->schema([
                        RepeatableEntry::make('trustLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('reason'),
                                TextEntry::make('score_delta'),
                                TextEntry::make('score_after'),
                                TextEntry::make('created_at')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn ($record): bool => $record->trustLogs()->exists()),
            ]);
    }
}
