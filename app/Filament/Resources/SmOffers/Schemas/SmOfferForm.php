<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOffers\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class SmOfferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_active')->label(__('supermarket_admin.form.is_active'))->default(true),
                DateTimePicker::make('starts_at')->label(__('supermarket_admin.form.starts_at'))->nullable(),
                DateTimePicker::make('ends_at')
                    ->label(__('supermarket_admin.form.ends_at'))
                    ->nullable()
                    ->rules(['nullable', 'date', 'after_or_equal:starts_at']),
            ]);
    }
}
