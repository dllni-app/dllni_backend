<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Schemas;

use App\Enums\MasterProductUnit;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class MasterProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label(__('supermarket_admin.form.master_product_name')),
                TextEntry::make('barcode')
                    ->label(__('supermarket_admin.form.master_product_barcode')),
                TextEntry::make('unit')
                    ->label(__('supermarket_admin.form.master_product_unit'))
                    ->formatStateUsing(fn (?MasterProductUnit $state): string => $state
                        ? __('supermarket_admin.enums.master_product_unit.'.$state->value)
                        : '—'),
                TextEntry::make('brand')
                    ->label(__('supermarket_admin.form.master_product_brand'))
                    ->placeholder('—'),
                TextEntry::make('description')
                    ->label(__('supermarket_admin.form.description'))
                    ->placeholder('—')
                    ->columnSpanFull(),
                IconEntry::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->boolean(),
                TextEntry::make('created_at')
                    ->label(__('supermarket_admin.infolist.created_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('updated_at')
                    ->label(__('supermarket_admin.infolist.updated_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
