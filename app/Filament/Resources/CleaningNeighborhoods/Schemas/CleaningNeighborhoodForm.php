<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

final class CleaningNeighborhoodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.cleaning_neighborhoods.sections.identity'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.name_ar'))
                            ->required()
                            ->maxLength(255)
                            ->rules([
                                Rule::unique('cleaning_neighborhoods', 'name_ar')
                                    ->ignore(fn ($record) => $record),
                            ]),
                        TextInput::make('name_en')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.name_en'))
                            ->maxLength(255),
                        TextInput::make('city_name')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.city_name'))
                            ->default(CleaningNeighborhoodNameNormalizer::ALEPPO_CITY)
                            ->required()
                            ->maxLength(100),
                        TextInput::make('sort_order')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.sort_order'))
                            ->numeric()
                            ->default(0)
                            ->required(),
                        TagsInput::make('aliases')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.aliases'))
                            ->separator(',')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('cleaning_admin.cleaning_neighborhoods.sections.map'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('center_latitude')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.center_latitude'))
                            ->numeric(),
                        TextInput::make('center_longitude')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.center_longitude'))
                            ->numeric(),
                        Toggle::make('is_active')
                            ->label(__('cleaning_admin.cleaning_neighborhoods.fields.is_active'))
                            ->default(true),
                    ]),
            ]);
    }
}
