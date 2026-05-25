<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Schemas\Schema;

final class WorkerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Worker profile')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->required(),
                        TextInput::make('first_name')
                            ->label('First name')
                            ->required(),
                        Select::make('gender')
                            ->label('Gender')
                            ->options([
                                'male' => 'Male',
                                'female' => 'Female',
                            ])
                            ->nullable(),
                        TextInput::make('bio')
                            ->label('Bio'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->live(),
                        TextInput::make('home_address')
                            ->label('Home address')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => (bool) $get('is_active')),
                        TextInput::make('home_latitude')
                            ->label('Home latitude')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90)
                            ->required(fn (Get $get): bool => (bool) $get('is_active')),
                        TextInput::make('home_longitude')
                            ->label('Home longitude')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180)
                            ->required(fn (Get $get): bool => (bool) $get('is_active')),
                        TextInput::make('trust_score')->numeric()->default(100)->hidden(),
                        TextInput::make('average_rating')->numeric()->default(0)->hidden(),
                        Toggle::make('is_suspended')->default(false)->hidden(),
                        Toggle::make('is_verified')->default(true)->hidden(),
                    ]),
            ]);
    }
}
