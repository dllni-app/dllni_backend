<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\Restaurants\Pages\EditRestaurant;
use App\Filament\Resources\Restaurants\Pages\ListRestaurants;
use App\Filament\Resources\Restaurants\Pages\ViewRestaurant;
use App\Filament\Resources\Restaurants\RelationManagers\CustomerReviewsRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\PenaltiesRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\ReputationLogsRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\RestaurantRolesRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\RestaurantStaffRelationManager;
use App\Filament\Resources\Restaurants\RelationManagers\ReviewsRelationManager;
use App\Filament\Resources\Restaurants\Schemas\RestaurantForm;
use App\Filament\Resources\Restaurants\Schemas\RestaurantInfolist;
use App\Filament\Resources\Restaurants\Tables\RestaurantsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\Restaurant;

final class RestaurantResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = Restaurant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'المطاعم';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::restaurants();
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.restaurant.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.restaurant.plural');
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة ملف المطعم ونقاط الثقة والتقييمات ومؤشرات الأداء.';
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestaurantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('restaurants.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('restaurants.view');
    }

    public static function canCreate(): bool
    {
        return self::dashboardAllowed('restaurants.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::dashboardAllowed('restaurants.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::dashboardAllowed('restaurants.delete');
    }

    public static function canManageGovernance(): bool
    {
        return self::dashboardAllowed('restaurants.update');
    }

    public static function getRelations(): array
    {
        return [
            ReputationLogsRelationManager::class,
            PenaltiesRelationManager::class,
            DocumentsRelationManager::class,
            RestaurantRolesRelationManager::class,
            RestaurantStaffRelationManager::class,
            ReviewsRelationManager::class,
            CustomerReviewsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurants::route('/'),
            'view' => ViewRestaurant::route('/{record}'),
            'edit' => EditRestaurant::route('/{record}/edit'),
        ];
    }
}
