<?php

namespace App\Filament\CleaningAdmin\Resources\SystemAlerts;

use App\Filament\CleaningAdmin\Resources\SystemAlerts\Pages\CreateSystemAlert;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Pages\EditSystemAlert;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Pages\ListSystemAlerts;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Pages\ViewSystemAlert;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Schemas\SystemAlertForm;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Schemas\SystemAlertInfolist;
use App\Filament\CleaningAdmin\Resources\SystemAlerts\Tables\SystemAlertsTable;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SystemAlertResource extends Resource
{
    protected static ?string $model = SystemAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'System Alerts';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return SystemAlertForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SystemAlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SystemAlertsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemAlerts::route('/'),
            'create' => CreateSystemAlert::route('/create'),
            'view' => ViewSystemAlert::route('/{record}'),
            'edit' => EditSystemAlert::route('/{record}/edit'),
        ];
    }
}
