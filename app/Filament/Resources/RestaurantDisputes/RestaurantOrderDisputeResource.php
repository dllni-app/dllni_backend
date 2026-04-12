<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes;

use App\Filament\Resources\RestaurantDisputes\Pages\EditRestaurantOrderDispute;
use App\Filament\Resources\RestaurantDisputes\Pages\ListRestaurantOrderDisputes;
use App\Filament\Resources\RestaurantDisputes\Pages\ViewRestaurantOrderDispute;
use App\Filament\Resources\RestaurantDisputes\Schemas\RestaurantOrderDisputeForm;
use App\Filament\Resources\RestaurantDisputes\Schemas\RestaurantOrderDisputeInfolist;
use App\Filament\Resources\RestaurantDisputes\Tables\RestaurantOrderDisputesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Resturants\Models\RestaurantOrderDispute;
use UnitEnum;

final class RestaurantOrderDisputeResource extends Resource
{
    protected static ?string $model = RestaurantOrderDispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'نزاعات المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?int $navigationSort = 4;

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة النزاعات واتخاذ القرارات المالية وإغلاق الحالات.';
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantOrderDisputeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestaurantOrderDisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantOrderDisputesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantOrderDisputes::route('/'),
            'view' => ViewRestaurantOrderDispute::route('/{record}'),
            'edit' => EditRestaurantOrderDispute::route('/{record}/edit'),
        ];
    }
}
