<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmOrderStatus;

final class SmOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $orderStatusLabels = collect(SmOrderStatus::cases())->mapWithKeys(
            fn ($c) => [$c->value => __('supermarket_admin.enums.order_status.'.$c->value)]
        )->all();

        return $schema
            ->components([
                Section::make(__('supermarket_admin.orders'))
                    ->schema([
                        TextEntry::make('order_number')->label(__('supermarket_admin.infolist.order_number')),
                        TextEntry::make('customer.name')->label(__('supermarket_admin.infolist.order_customer'))->placeholder('—'),
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('supermarket_admin.form.status'))
                            ->formatStateUsing(fn ($state) => $state ? ($orderStatusLabels[$state->value] ?? $state->value) : '—')
                            ->badge(),
                        TextEntry::make('pickup_scheduled_for')->label(__('supermarket_admin.infolist.pickup_scheduled'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label(__('supermarket_admin.infolist.ready_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label(__('supermarket_admin.infolist.picked_up_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('customer_pickup_confirmed_at')->label(__('supermarket_admin.infolist.customer_confirmed'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('total_amount')
                            ->label(__('supermarket_admin.infolist.total_amount'))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                        TextEntry::make('cancellation_fee_amount')
                            ->label(__('supermarket_admin.infolist.cancellation_fee'))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make(__('supermarket_admin.infolist.status_timeline'))
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('to_status')->label(__('supermarket_admin.form.status')),
                                TextEntry::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible(),
            ]);
    }
}
