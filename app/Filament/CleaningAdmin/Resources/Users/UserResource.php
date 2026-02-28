<?php

namespace App\Filament\CleaningAdmin\Resources\Users;

use App\Filament\CleaningAdmin\Resources\Users\Pages\CreateUser;
use App\Filament\CleaningAdmin\Resources\Users\Pages\EditUser;
use App\Filament\CleaningAdmin\Resources\Users\Pages\ListUsers;
use App\Filament\CleaningAdmin\Resources\Users\Pages\ViewUser;
use App\Filament\CleaningAdmin\Resources\Users\Schemas\UserForm;
use App\Filament\CleaningAdmin\Resources\Users\Schemas\UserInfolist;
use App\Filament\CleaningAdmin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $navigationLabel = 'Admin Users';

    protected static ?string $navigationGroup = 'Permissions';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->role('admin');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
