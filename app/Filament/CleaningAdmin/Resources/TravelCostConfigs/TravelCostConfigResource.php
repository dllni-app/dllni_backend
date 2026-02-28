<?php

namespace App\Filament\CleaningAdmin\Resources\TravelCostConfigs;

use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages\CreateTravelCostConfig;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages\EditTravelCostConfig;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages\ListTravelCostConfigs;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Pages\ViewTravelCostConfig;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Schemas\TravelCostConfigForm;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Schemas\TravelCostConfigInfolist;
use App\Filament\CleaningAdmin\Resources\TravelCostConfigs\Tables\TravelCostConfigsTable;
use App\Models\TravelCostConfig;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TravelCostConfigResource extends Resource
{
    protected static ?string $model = TravelCostConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Travel Cost Rules';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 4;

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
