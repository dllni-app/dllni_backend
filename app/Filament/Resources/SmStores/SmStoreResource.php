<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmStores\Pages\ListSmStores;
use App\Filament\Resources\SmStores\Pages\ViewSmStore;
use App\Filament\Resources\SmStores\RelationManagers\CommissionRulesRelationManager;
use App\Filament\Resources\SmStores\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\SmStores\Schemas\SmStoreInfolist;
use App\Filament\Resources\SmStores\Tables\SmStoresTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmStore;

final class SmStoreResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmStore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.stores');
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.supermarket_store.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.supermarket_store.plural');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.stores');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmStoreInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmStoresTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
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

    public static function canManageGovernance(): bool
    {
        return self::dashboardAllowed('supermarket_stores.update');
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            CommissionRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmStores::route('/'),
            'view' => ViewSmStore::route('/{record}'),
        ];
    }
}
