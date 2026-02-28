<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts;

use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages\EditRestaurantSystemAlert;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages\ListRestaurantSystemAlerts;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Pages\ViewRestaurantSystemAlert;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Schemas\RestaurantSystemAlertForm;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Schemas\RestaurantSystemAlertInfolist;
use App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Tables\RestaurantSystemAlertsTable;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Resturants\Models\Order;
use UnitEnum;

final class RestaurantSystemAlertResource extends Resource
{
    protected static ?string $model = SystemAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'تنبيهات المطاعم';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    public static function getNavigationTooltip(): ?string
    {
        return 'تنبيهات استباقية للحالات غير الطبيعية مع إجراءات سريعة.';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('booking_type', Order::class);
    }

    public static function form(Schema $schema): Schema
    {
        return RestaurantSystemAlertForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestaurantSystemAlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantSystemAlertsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantSystemAlerts::route('/'),
            'view' => ViewRestaurantSystemAlert::route('/{record}'),
            'edit' => EditRestaurantSystemAlert::route('/{record}/edit'),
        ];
    }
}
