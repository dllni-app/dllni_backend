<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\ServiceCategory;

final class CleaningServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('cleaning_admin.cleaning_services.fields.name'))
                    ->required(),
                Select::make('category')
                    ->label(__('cleaning_admin.cleaning_services.fields.category'))
                    ->options(collect(ServiceCategory::cases())->mapWithKeys(fn (ServiceCategory $category): array => [$category->value => $category->label()])->all())
                    ->required(),
                Textarea::make('description')
                    ->label(__('cleaning_admin.cleaning_services.fields.description')),
                TextInput::make('price')
                    ->label(__('cleaning_admin.cleaning_services.fields.price'))
                    ->required()
                    ->numeric()
                    ->minValue(0),
                Toggle::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.fields.is_active'))
                    ->default(true),
            ]);
    }
}
