<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryFinancialAccount;

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
                Section::make(__('delivery_company.drivers.sections.operations'))
                    ->schema([
                        TextEntry::make('active_order_number')
                            ->label(__('delivery_company.drivers.fields.active_order'))
                            ->state(fn ($record) => $record->orders()
                                ->whereIn('status', ['accepted', 'in_progress', 'picked_up', 'returning_to_merchant'])
                                ->latest('updated_at')
                                ->value('order_number'))
                            ->placeholder('—'),
                        TextEntry::make('active_order_status')
                            ->label(__('delivery_company.drivers.fields.active_order_status'))
                            ->state(fn ($record) => $record->orders()
                                ->whereIn('status', ['accepted', 'in_progress', 'picked_up', 'returning_to_merchant'])
                                ->latest('updated_at')
                                ->value('status'))
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? __('delivery_company.orders.enums.status.'.$state)
                                : '—'),
                        TextEntry::make('completed_orders_count')
                            ->label(__('delivery_company.drivers.fields.completed_orders_count'))
                            ->state(fn ($record): int => $record->orders()->where('status', 'completed')->count()),
                        TextEntry::make('rejected_offers_count')
                            ->label(__('delivery_company.drivers.fields.rejected_offers_count'))
                            ->state(fn ($record): int => $record->assignmentAttempts()->where('status', 'rejected')->count()),
                        TextEntry::make('missed_offers_count')
                            ->label(__('delivery_company.drivers.fields.missed_offers_count'))
                            ->state(fn ($record): int => $record->assignmentAttempts()->where('status', 'timed_out')->count()),
                    ])
                    ->columns(3),
                Section::make(__('delivery_company.drivers.sections.financial'))
                    ->schema([
                        TextEntry::make('financial_balance')
                            ->label(__('delivery_company.drivers.fields.financial_balance'))
                            ->state(fn (DeliveryDriver $record): float => (float) (self::financialAccount($record)?->current_balance ?? 0))
                            ->money(fn (DeliveryDriver $record): string => (string) (self::financialAccount($record)?->currency ?? 'SYP')),
                        TextEntry::make('financial_currency')
                            ->label(__('delivery_company.drivers.fields.financial_currency'))
                            ->state(fn (DeliveryDriver $record): string => (string) (self::financialAccount($record)?->currency ?? 'SYP')),
                        TextEntry::make('financial_transactions_count')
                            ->label(__('delivery_company.drivers.fields.financial_transactions_count'))
                            ->state(fn (DeliveryDriver $record): int => self::financialAccount($record)?->transactions()->count() ?? 0),
                        TextEntry::make('latest_financial_transaction')
                            ->label(__('delivery_company.drivers.fields.latest_financial_transaction'))
                            ->state(function (DeliveryDriver $record): string {
                                $transaction = self::financialAccount($record)?->transactions()->latest('created_at')->first();
                                if (! $transaction) {
                                    return '—';
                                }

                                return number_format((float) $transaction->amount, 2).' '.(self::financialAccount($record)?->currency ?? 'SYP').' · '.$transaction->created_at?->format('Y-m-d H:i');
                            }),
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
                        TextEntry::make('latest_location_at')
                            ->label(__('delivery_company.drivers.fields.latest_location_at'))
                            ->state(fn ($record) => $record->locations()->latest('recorded_at')->value('recorded_at'))
                            ->dateTime('Y-m-d H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('map_link')
                            ->label(__('delivery_company.drivers.fields.map'))
                            ->state(fn (): string => __('delivery_company.orders.actions.open_map'))
                            ->url(function ($record): ?string {
                                $location = $record->locations()->latest('recorded_at')->first();
                                if (! $location) {
                                    return null;
                                }

                                return 'https://www.google.com/maps?q='.$location->latitude.','.$location->longitude;
                            })
                            ->openUrlInNewTab(),
                    ])
                    ->columns(2),
                Section::make(__('delivery_company.drivers.sections.trust_history'))
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

    private static function financialAccount(DeliveryDriver $driver): ?DeliveryFinancialAccount
    {
        if ($driver->relationLoaded('financialAccount')) {
            return $driver->getRelation('financialAccount');
        }

        $account = DeliveryFinancialAccount::query()
            ->where('owner_type', DeliveryDriver::class)
            ->where('owner_id', $driver->id)
            ->where('currency', 'SYP')
            ->first();

        $driver->setRelation('financialAccount', $account);

        return $account;
    }
}
