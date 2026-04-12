<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores;

use App\Filament\Resources\SmStores\Pages\EditSmStore;
use App\Filament\Resources\SmStores\Pages\ListSmStores;
use App\Filament\Resources\SmStores\Pages\ViewSmStore;
use App\Filament\Resources\SmStores\Schemas\SmStoreForm;
use App\Filament\Resources\SmStores\Schemas\SmStoreInfolist;
use App\Filament\Resources\SmStores\Tables\SmStoresTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmStore;
use UnitEnum;

final class SmStoreResource extends Resource
{
    protected static ?string $model = SmStore::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.stores');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.stores');
    }

    public static function form(Schema $schema): Schema
    {
        return SmStoreForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmStoreInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmStoresTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmStores::route('/'),
            'view' => ViewSmStore::route('/{record}'),
            'edit' => EditSmStore::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
