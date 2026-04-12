<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs;

use App\Filament\Resources\TravelCostConfigs\Pages\CreateTravelCostConfig;
use App\Filament\Resources\TravelCostConfigs\Pages\EditTravelCostConfig;
use App\Filament\Resources\TravelCostConfigs\Pages\ListTravelCostConfigs;
use App\Filament\Resources\TravelCostConfigs\Pages\ViewTravelCostConfig;
use App\Filament\Resources\TravelCostConfigs\Schemas\TravelCostConfigForm;
use App\Filament\Resources\TravelCostConfigs\Schemas\TravelCostConfigInfolist;
use App\Filament\Resources\TravelCostConfigs\Tables\TravelCostConfigsTable;
use App\Models\TravelCostConfig;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class TravelCostConfigResource extends Resource
{
    protected static ?string $model = TravelCostConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.travel_cost_configs.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.travel_cost_configs.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return TravelCostConfigForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TravelCostConfigInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TravelCostConfigsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTravelCostConfigs::route('/'),
            'create' => CreateTravelCostConfig::route('/create'),
            'view' => ViewTravelCostConfig::route('/{record}'),
            'edit' => EditTravelCostConfig::route('/{record}/edit'),
        ];
    }
}
