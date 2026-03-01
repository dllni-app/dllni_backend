<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class WorkerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')->relationship('user', 'name')->searchable()->required(),
                TextInput::make('first_name')->required(),
                TextInput::make('bio'),
                TextInput::make('trust_score')->numeric()->default(0),
                TextInput::make('average_rating')->numeric()->default(0),
                Toggle::make('is_active')->default(true),
                Toggle::make('is_suspended')->default(false),
                Toggle::make('is_verified')->default(false),
                Toggle::make('is_featured')->default(false),
            ]);
    }
}
