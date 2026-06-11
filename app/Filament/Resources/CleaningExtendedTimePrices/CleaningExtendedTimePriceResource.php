<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningExtendedTimePrices;

use App\Filament\Resources\CleaningExtendedTimePrices\Pages\EditCleaningExtendedTimePrice;
use App\Filament\Resources\CleaningExtendedTimePrices\Pages\ListCleaningExtendedTimePrices;
use App\Filament\Resources\CleaningExtendedTimePrices\Schemas\CleaningExtendedTimePriceForm;
use App\Filament\Resources\CleaningExtendedTimePrices\Tables\CleaningExtendedTimePricesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningExtendedTimePrice;

final class CleaningExtendedTimePriceResource extends Resource
{
    protected static ?string $model = CleaningExtendedTimePrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.extended_time_prices.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.extended_time_prices.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.extended_time_prices.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.extended_time_prices.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningExtendedTimePriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningExtendedTimePricesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderBy('sort_order');
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('pricing.view');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('pricing.update');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
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
            'index' => ListCleaningExtendedTimePrices::route('/'),
            'edit' => EditCleaningExtendedTimePrice::route('/{record}/edit'),
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
