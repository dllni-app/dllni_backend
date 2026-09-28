<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmOrders\Pages\ListSmOrders;
use App\Filament\Resources\SmOrders\Pages\ViewSmOrder;
use App\Filament\Resources\SmOrders\Schemas\SmOrderInfolist;
use App\Filament\Resources\SmOrders\Tables\SmOrdersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 3;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.orders');
    }

    public static function getModelLabel(): string
    {
        return 'طلب متجر';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات المتاجر';
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.orders');
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmOrdersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_orders.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_orders.view');
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

    public static function getPages(): array
    {
        return [
            'index' => ListSmOrders::route('/'),
            'view' => ViewSmOrder::route('/{record}'),
        ];
    }
}
