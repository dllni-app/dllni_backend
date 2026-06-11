<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningExtendedTimePrices\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class CleaningExtendedTimePriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('start_minutes')
                    ->label(__('cleaning_admin.extended_time_prices.fields.start_minutes'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('end_minutes')
                    ->label(__('cleaning_admin.extended_time_prices.fields.end_minutes'))
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('price')
                    ->label(__('cleaning_admin.extended_time_prices.fields.price'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }
}
