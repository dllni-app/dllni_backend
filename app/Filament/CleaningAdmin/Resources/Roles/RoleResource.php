<?php

namespace App\Filament\CleaningAdmin\Resources\Roles;

use App\Filament\CleaningAdmin\Resources\Roles\Pages\CreateRole;
use App\Filament\CleaningAdmin\Resources\Roles\Pages\EditRole;
use App\Filament\CleaningAdmin\Resources\Roles\Pages\ListRoles;
use App\Filament\CleaningAdmin\Resources\Roles\Pages\ViewRole;
use App\Filament\CleaningAdmin\Resources\Roles\Schemas\RoleForm;
use App\Filament\CleaningAdmin\Resources\Roles\Schemas\RoleInfolist;
use App\Filament\CleaningAdmin\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $navigationGroup = 'Permissions';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
