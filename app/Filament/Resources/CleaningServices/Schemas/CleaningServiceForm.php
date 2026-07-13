<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Schemas;

use Filament\Forms\Components\Hidden;
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
                Hidden::make('category')
                    ->default(ServiceCategory::Cleaning->value),
                Toggle::make('is_active')
                    ->label(__('cleaning_admin.cleaning_services.fields.is_active'))
                    ->default(true),
            ]);
    }
}
