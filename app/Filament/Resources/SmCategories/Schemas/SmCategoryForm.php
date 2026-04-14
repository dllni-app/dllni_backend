<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

final class SmCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('store_id')
                    ->relationship('store', 'name')
                    ->label(__('supermarket_admin.form.category_store'))
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->label(__('supermarket_admin.form.category_name'))
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (filled($state) && blank($get('slug'))) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->label(__('supermarket_admin.form.category_slug'))
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(
                        table: 'sm_categories',
                        column: 'slug',
                        ignorable: null,
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                            return $rule->where('store_id', (int) $get('store_id'));
                        },
                    ),
                Textarea::make('description')
                    ->label(__('supermarket_admin.form.description'))
                    ->columnSpanFull()
                    ->rows(3)
                    ->maxLength(65535),
                TextInput::make('sort_order')
                    ->label(__('supermarket_admin.form.sort_order'))
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                TextInput::make('image_path')
                    ->label(__('supermarket_admin.form.image_path'))
                    ->maxLength(255)
                    ->nullable()
                    ->helperText(__('supermarket_admin.form.image_path_help')),
                Toggle::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->default(true),
            ]);
    }
}
