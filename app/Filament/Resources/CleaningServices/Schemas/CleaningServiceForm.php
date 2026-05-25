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
                TextInput::make('name')->required(),
                Select::make('category')
                    ->options([
                        ServiceCategory::Cleaning->value => 'cleaning',
                        ServiceCategory::EventAssistance->value => 'event_assisent',
                    ])
                    ->required(),
                Textarea::make('description'),
                TextInput::make('price')->required()->numeric()->minValue(0),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
