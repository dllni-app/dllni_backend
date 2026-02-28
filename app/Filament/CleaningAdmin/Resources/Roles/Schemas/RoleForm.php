<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;

final class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('guard_name')
                    ->label('الحارس')
                    ->default('web')
                    ->required()
                    ->dehydrated(),
                CheckboxList::make('permissions')
                    ->label('الصلاحيات')
                    ->options(
                        Permission::where('guard_name', 'web')->orderBy('name')->pluck('name', 'name')
                    )
                    ->columns(2)
                    ->searchable()
                    ->dehydrated(true),
            ]);
    }
}
