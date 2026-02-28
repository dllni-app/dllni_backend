<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDailyStats\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmStoreDailyStatInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.daily_stats'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('date')->label(__('supermarket_admin.infolist.date'))->date('Y-m-d'),
                        TextEntry::make('orders_count')->label(__('supermarket_admin.infolist.orders_count')),
                        TextEntry::make('orders_revenue')->label(__('supermarket_admin.infolist.orders_revenue'))->money(config('app.currency', 'IQD')),
                        TextEntry::make('unique_customers')->label(__('supermarket_admin.infolist.unique_customers')),
                        TextEntry::make('new_customers')->label(__('supermarket_admin.infolist.new_customers')),
                    ])
                    ->columns(2),
            ]);
    }
}
