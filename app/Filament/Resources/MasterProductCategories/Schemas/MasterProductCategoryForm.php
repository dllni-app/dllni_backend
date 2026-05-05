<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class MasterProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('supermarket_admin.form.master_category_name'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (filled($state) && blank($get('slug'))) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('supermarket_admin.form.master_category_slug'))
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(
                        table: 'master_product_categories',
                        column: 'slug',
                        ignorable: null,
                        ignoreRecord: true,
                    ),
                Textarea::make('description')
                    ->label(__('supermarket_admin.form.description'))
                    ->columnSpanFull()
                    ->rows(3)
                    ->maxLength(65535)
                    ->helperText(__('supermarket_admin.form.master_category_description_help')),
                TextInput::make('sort_order')
                    ->label(__('supermarket_admin.form.sort_order'))
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Toggle::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->default(true),
            ]);
    }
}
