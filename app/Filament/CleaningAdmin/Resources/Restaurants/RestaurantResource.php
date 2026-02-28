<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Restaurants;

use App\Filament\CleaningAdmin\Resources\Restaurants\Pages\EditRestaurant;
use App\Filament\CleaningAdmin\Resources\Restaurants\Pages\ListRestaurants;
use App\Filament\CleaningAdmin\Resources\Restaurants\Pages\ViewRestaurant;
use App\Filament\CleaningAdmin\Resources\Restaurants\RelationManagers\CustomerReviewsRelationManager;
use App\Filament\CleaningAdmin\Resources\Restaurants\RelationManagers\ReputationLogsRelationManager;
use App\Filament\CleaningAdmin\Resources\Restaurants\RelationManagers\ReviewsRelationManager;
use App\Filament\CleaningAdmin\Resources\Restaurants\Schemas\RestaurantForm;
use App\Filament\CleaningAdmin\Resources\Restaurants\Schemas\RestaurantInfolist;
use App\Filament\CleaningAdmin\Resources\Restaurants\Tables\RestaurantsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Resturants\Models\Restaurant;
use UnitEnum;

final class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = 'المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

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

    public static function getRelations(): array
    {
        return [
            ReputationLogsRelationManager::class,
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
