<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts;

use App\Filament\Resources\SystemAlerts\Pages\CreateSystemAlert;
use App\Filament\Resources\SystemAlerts\Pages\EditSystemAlert;
use App\Filament\Resources\SystemAlerts\Pages\ListSystemAlerts;
use App\Filament\Resources\SystemAlerts\Pages\ViewSystemAlert;
use App\Filament\Resources\SystemAlerts\Schemas\SystemAlertForm;
use App\Filament\Resources\SystemAlerts\Schemas\SystemAlertInfolist;
use App\Filament\Resources\SystemAlerts\Tables\SystemAlertsTable;
use App\Models\SystemAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class SystemAlertResource extends Resource
{
    protected static ?string $model = SystemAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.system_alerts.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.system_alerts.tooltip');
    }

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
