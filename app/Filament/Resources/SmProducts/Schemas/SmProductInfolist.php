<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('supermarket_admin.products'))
                    ->schema([
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('name')->label(__('supermarket_admin.infolist.product_name')),
                        TextEntry::make('source_type')
                            ->label(__('supermarket_admin.form.product_source'))
                            ->formatStateUsing(fn ($state) => $state ? __('supermarket_admin.enums.product_source.'.$state->value) : '—'),
                        TextEntry::make('price')->label(__('supermarket_admin.form.price'))->money(config('app.currency', 'IQD')),
                        TextEntry::make('discounted_price')->label(__('supermarket_admin.form.discounted_price'))->money(config('app.currency', 'IQD'))->placeholder('—'),
                        TextEntry::make('stock_quantity')->label(__('supermarket_admin.form.stock_quantity')),
                        TextEntry::make('low_stock_threshold')->label(__('supermarket_admin.form.low_stock_threshold')),
                        TextEntry::make('expires_at')->label(__('supermarket_admin.form.expires_at'))->dateTime('Y-m-d')->placeholder('—'),
                        TextEntry::make('is_available')->label(__('supermarket_admin.form.is_active'))->formatStateUsing(fn (?bool $s) => $s ? __('supermarket_admin.enums.boolean.yes') : __('supermarket_admin.enums.boolean.no'))->badge(),
                    ])
                    ->columns(3),
            ]);
    }
}
