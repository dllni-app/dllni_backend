<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryCompanySettings\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryCompanySettingsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('delivery_company.company.sections.profile'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('delivery_company.company.fields.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('legal_name')
                        ->label(__('delivery_company.company.fields.legal_name'))
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label(__('delivery_company.company.fields.phone'))
                        ->tel()
                        ->maxLength(50),
                    TextInput::make('email')
                        ->label(__('delivery_company.company.fields.email'))
                        ->email()
                        ->maxLength(255),
                    Textarea::make('address')
                        ->label(__('delivery_company.company.fields.address'))
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    TextInput::make('latitude')
                        ->label(__('delivery_company.company.fields.latitude'))
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90),
                    TextInput::make('longitude')
                        ->label(__('delivery_company.company.fields.longitude'))
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180),
                ])
                ->columns(2),
        ]);
    }
}
