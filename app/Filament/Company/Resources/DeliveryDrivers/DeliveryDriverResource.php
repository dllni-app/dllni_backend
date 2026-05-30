<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDrivers;

use App\Enums\PermissionGroup;
use App\Filament\Company\Concerns\InteractsWithDeliveryCompany;
use App\Filament\Company\Resources\DeliveryDrivers\Pages\CreateDeliveryDriver;
use App\Filament\Company\Resources\DeliveryDrivers\Pages\ListDeliveryDrivers;
use App\Filament\Company\Resources\DeliveryDrivers\Pages\ViewDeliveryDriver;
use App\Filament\Company\Resources\DeliveryDrivers\Schemas\DeliveryDriverForm;
use App\Filament\Company\Resources\DeliveryDrivers\Schemas\DeliveryDriverInfolist;
use App\Filament\Company\Resources\DeliveryDrivers\Tables\DeliveryDriversTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverResource extends Resource
{
    use InteractsWithDeliveryCompany;

    protected static ?string $model = DeliveryDriver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_company.nav_groups.drivers');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_company.drivers.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_company.drivers.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_company.drivers.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryDriverForm::configure($schema);
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
        return self::companyScopedQuery(
            parent::getEloquentQuery()->with(['user', 'locations' => fn ($query) => $query->latest('recorded_at')->limit(1)]),
        );
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryDrivers->value.'.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can(PermissionGroup::DeliveryDrivers->value.'.create') ?? false;
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
            'create' => CreateDeliveryDriver::route('/create'),
            'view' => ViewDeliveryDriver::route('/{record}'),
        ];
    }
}
