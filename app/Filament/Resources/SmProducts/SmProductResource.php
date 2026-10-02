<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmProducts\Pages\EditSmProduct;
use App\Filament\Resources\SmProducts\Pages\ListSmProducts;
use App\Filament\Resources\SmProducts\Pages\ViewSmProduct;
use App\Filament\Resources\SmProducts\Schemas\SmProductForm;
use App\Filament\Resources\SmProducts\Schemas\SmProductInfolist;
use App\Filament\Resources\SmProducts\Tables\SmProductsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmProduct;

final class SmProductResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.products');
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.supermarket_product.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.supermarket_product.plural');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.products');
    }

    public static function form(Schema $schema): Schema
    {
        return SmProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmProductsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canModerate(): bool
    {
        return self::dashboardAllowed('supermarket_catalog.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmProducts::route('/'),
            'view' => ViewSmProduct::route('/{record}'),
            'edit' => EditSmProduct::route('/{record}/edit'),
        ];
    }
}
