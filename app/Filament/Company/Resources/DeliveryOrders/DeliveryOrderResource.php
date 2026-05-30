<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryOrders;

use App\Enums\PermissionGroup;
use App\Filament\Company\Concerns\InteractsWithDeliveryCompany;
use App\Filament\Company\Resources\DeliveryOrders\Pages\CreateDeliveryOrder;
use App\Filament\Company\Resources\DeliveryOrders\Pages\ListDeliveryOrders;
use App\Filament\Company\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Filament\Company\Resources\DeliveryOrders\Schemas\DeliveryOrderForm;
use App\Filament\Company\Resources\DeliveryOrders\Schemas\DeliveryOrderInfolist;
use App\Filament\Company\Resources\DeliveryOrders\Tables\DeliveryOrdersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryOrder;

final class DeliveryOrderResource extends Resource
{
    use InteractsWithDeliveryCompany;

    protected static ?string $model = DeliveryOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.orders.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_company.orders.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_company.orders.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryOrderForm::configure($schema);
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
        return self::companyScopedQuery(
            parent::getEloquentQuery()->with(['driver', 'events', 'assignmentAttempts.driver']),
        );
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryOrders->value.'.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryOrders->value.'.create') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
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
            'index' => ListDeliveryOrders::route('/'),
            'create' => CreateDeliveryOrder::route('/create'),
            'view' => ViewDeliveryOrder::route('/{record}'),
        ];
    }
}
