<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class CleaningServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required(),
                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                Select::make('category')
                    ->options([
                        'home' => 'Home',
                        'office' => 'Office',
                        'event' => 'Event',
                    ])
                    ->required(),
                Textarea::make('description'),
                Toggle::make('is_active')->default(true),
            ]);
    }
}
