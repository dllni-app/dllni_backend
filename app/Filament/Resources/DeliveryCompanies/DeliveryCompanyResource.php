<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryCompanies;

use App\Filament\Resources\DeliveryCompanies\Pages\ListDeliveryCompanies;
use App\Filament\Resources\DeliveryCompanies\Pages\ViewDeliveryCompany;
use App\Filament\Resources\DeliveryCompanies\Schemas\DeliveryCompanyInfolist;
use App\Filament\Resources\DeliveryCompanies\Tables\DeliveryCompaniesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryCompany;

final class DeliveryCompanyResource extends Resource
{
    protected static ?string $model = DeliveryCompany::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 45;

    public static function getNavigationGroup(): ?string
    {
        return __('delivery_admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('delivery_admin.companies.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('delivery_admin.companies.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('delivery_admin.companies.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryCompanyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryCompaniesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('delivery_companies.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('delivery_companies.view');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryCompanies::route('/'),
            'view' => ViewDeliveryCompany::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['financialAccount', 'owner']);
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
