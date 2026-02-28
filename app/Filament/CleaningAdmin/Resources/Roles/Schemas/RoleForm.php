<?php

namespace App\Filament\CleaningAdmin\Resources\Roles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->unique(ignoreRecord: true),
                TextInput::make('guard_name')->default('web')->required(),
            ]);
    }
}
