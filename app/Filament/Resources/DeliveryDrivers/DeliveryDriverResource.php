<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDrivers;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\DeliveryDrivers\Pages\ListDeliveryDrivers;
use App\Filament\Resources\DeliveryDrivers\Pages\ViewDeliveryDriver;
use App\Filament\Resources\DeliveryDrivers\Schemas\DeliveryDriverInfolist;
use App\Filament\Resources\DeliveryDrivers\Tables\DeliveryDriversTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = DeliveryDriver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'مندوبي التوصيل';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التوصيل';

    protected static ?int $navigationSort = 3;

    public static function getNavigationTooltip(): ?string
    {
        return 'متابعة حالة المندوبين والثقة وآخر ظهور وموقع عبر جميع شركات التوصيل.';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryDriverInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryDriversTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'company',
            'user',
            'latestLocation',
        ])->withCount([
            'orders',
            'trustLogs',
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
            'index' => ListDeliveryDrivers::route('/'),
            'view' => ViewDeliveryDriver::route('/{record}'),
        ];
    }
}
