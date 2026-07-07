<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOwners;

use App\Enums\UserModuleType;
use App\Filament\Resources\RestaurantOwners\Pages\CreateRestaurantOwner;
use App\Filament\Resources\RestaurantOwners\Pages\EditRestaurantOwner;
use App\Filament\Resources\RestaurantOwners\Pages\ListRestaurantOwners;
use App\Filament\Resources\RestaurantOwners\Pages\ViewRestaurantOwner;
use App\Filament\Resources\RestaurantOwners\Schemas\RestaurantOwnerForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class RestaurantOwnerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): ?string
    {
        return __('restaurant_admin.owner_management.group');
    }

    public static function getNavigationLabel(): string
    {
        return 'Restaurant Owners';
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantOwnerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('module_type', UserModuleType::RestaurantSeller);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantOwners::route('/'),
            'create' => CreateRestaurantOwner::route('/create'),
            'view' => ViewRestaurantOwner::route('/{record}'),
            'edit' => EditRestaurantOwner::route('/{record}/edit'),
        ];
    }
}
