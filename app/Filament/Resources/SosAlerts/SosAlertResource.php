<?php

declare(strict_types=1);

namespace App\Filament\Resources\SosAlerts;

use App\Filament\Resources\SosAlerts\Pages\ListSosAlerts;
use App\Filament\Resources\SosAlerts\Pages\ViewSosAlert;
use App\Filament\Resources\SosAlerts\Schemas\SosAlertInfolist;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SosAlertResource extends Resource
{
    protected static ?string $model = SosAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'SOS Alerts';
    }

    public static function getModelLabel(): string
    {
        return 'SOS Alert';
    }

    public static function getPluralModelLabel(): string
    {
        return 'SOS Alerts';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SosAlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SosAlertsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::isDashboardAdmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isDashboardAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'user',
            'order',
            'acknowledgedBy',
            'resolvedBy',
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSosAlerts::route('/'),
            'view' => ViewSosAlert::route('/{record}'),
        ];
    }

    private static function isDashboardAdmin(): bool
    {
        $user = auth()->user();

        return $user?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }
}
