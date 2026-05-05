<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Schemas;

use App\Enums\MasterProductUnit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class MasterProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $unitOptions = collect(MasterProductUnit::cases())->mapWithKeys(
            fn(MasterProductUnit $unit): array => [$unit->value => __('supermarket_admin.enums.master_product_unit.' . $unit->value)]
        )->all();

        return $schema
            ->components([
                Select::make('category_id')
                    ->label(__('supermarket_admin.form.master_product_category'))
                    ->relationship('category', 'name', fn($query) => $query->orderBy('sort_order')->orderBy('name'))
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText(__('supermarket_admin.form.master_product_category_help')),
                TextInput::make('name')
                    ->label(__('supermarket_admin.form.master_product_name'))
                    ->required()
                    ->maxLength(255),
                Select::make('unit')
                    ->label(__('supermarket_admin.form.master_product_unit'))
                    ->options($unitOptions)
                    ->required()
                    ->native(false),
                TextInput::make('brand')
                    ->label(__('supermarket_admin.form.master_product_brand'))
                    ->maxLength(255)
                    ->nullable(),
                Textarea::make('description')
                    ->label(__('supermarket_admin.form.description'))
                    ->columnSpanFull()
                    ->rows(3)
                    ->maxLength(65535)
                    ->nullable(),
                Toggle::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->default(true),
            ]);
    }
}
