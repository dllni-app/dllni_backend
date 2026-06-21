<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods;

use App\Filament\Resources\CleaningNeighborhoods\Pages\CreateCleaningNeighborhood;
use App\Filament\Resources\CleaningNeighborhoods\Pages\EditCleaningNeighborhood;
use App\Filament\Resources\CleaningNeighborhoods\Pages\ListCleaningNeighborhoods;
use App\Filament\Resources\CleaningNeighborhoods\Schemas\CleaningNeighborhoodForm;
use App\Filament\Resources\CleaningNeighborhoods\Tables\CleaningNeighborhoodsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class CleaningNeighborhoodResource extends Resource
{
    protected static ?string $model = CleaningNeighborhood::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 27;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.cleaning_neighborhoods.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.cleaning_neighborhoods.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.cleaning_neighborhoods.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.cleaning_neighborhoods.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningNeighborhoodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningNeighborhoodsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('settings.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('settings.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('settings.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('settings.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningNeighborhoods::route('/'),
            'create' => CreateCleaningNeighborhood::route('/create'),
            'edit' => EditCleaningNeighborhood::route('/{record}/edit'),
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
