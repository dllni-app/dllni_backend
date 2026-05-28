<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts;

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
use Illuminate\Database\Eloquent\Model;

final class SystemAlertResource extends Resource
{
    protected static ?string $model = SystemAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.system_alerts.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.system_alerts.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.system_alerts.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.system_alerts.plural');
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

    public static function canViewAny(): bool
    {
        return self::hasPermission('system_alerts.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('system_alerts.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('system_alerts.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSystemAlerts::route('/'),
            'view' => ViewSystemAlert::route('/{record}'),
            'edit' => EditSystemAlert::route('/{record}/edit'),
        ];
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
