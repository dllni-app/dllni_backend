<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryOrders;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\DeliveryOrders\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Schemas\DeliveryOrderInfolist;
use App\Filament\Resources\DeliveryOrders\Tables\DeliveryOrdersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliveryOrderResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = DeliveryOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'طلبات التوصيل';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التوصيل';

    protected static ?int $navigationSort = 2;

    public static function getNavigationTooltip(): ?string
    {
        return 'متابعة طلبات التوصيل عبر جميع الشركات والتدخل في الحالات الاستثنائية.';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryOrdersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'company',
            'driver.user',
            'driver.latestLocation',
            'source',
        ]);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('platform_delivery_operations.view');
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
            'index' => ListDeliveryOrders::route('/'),
            'view' => ViewDeliveryOrder::route('/{record}'),
        ];
    }
}
