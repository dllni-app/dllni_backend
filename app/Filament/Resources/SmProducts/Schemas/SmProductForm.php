<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmProductSource;

final class SmProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $sourceOptions = collect(SmProductSource::cases())->mapWithKeys(
            fn (SmProductSource $c) => [$c->value => __('supermarket_admin.enums.product_source.'.$c->value)]
        )->all();

        return $schema
            ->components([
                TextInput::make('name')->label(__('supermarket_admin.infolist.name'))->required()->maxLength(255),
                Select::make('source_type')
                    ->label(__('supermarket_admin.form.product_source'))
                    ->options($sourceOptions)
                    ->required()
                    ->native(false),
                TextInput::make('price')->label(__('supermarket_admin.form.price'))->numeric()->required()->minValue(0)->prefix(config('app.currency', 'SYP')),
                TextInput::make('discounted_price')->label(__('supermarket_admin.form.discounted_price'))->numeric()->minValue(0)->prefix(config('app.currency', 'SYP')),
                TextInput::make('stock_quantity')->label(__('supermarket_admin.form.stock_quantity'))->numeric()->required()->minValue(0)->default(0),
                TextInput::make('low_stock_threshold')->label(__('supermarket_admin.form.low_stock_threshold'))->numeric()->minValue(0)->default(0),
                DateTimePicker::make('expires_at')->label(__('supermarket_admin.form.expires_at'))->nullable(),
                Toggle::make('is_available')->label(__('supermarket_admin.form.is_active'))->default(true),
            ]);
    }
}
