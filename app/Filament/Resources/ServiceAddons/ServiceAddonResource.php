<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons;

use App\Filament\Resources\ServiceAddons\Pages\CreateServiceAddon;
use App\Filament\Resources\ServiceAddons\Pages\EditServiceAddon;
use App\Filament\Resources\ServiceAddons\Pages\ListServiceAddons;
use App\Filament\Resources\ServiceAddons\Pages\ViewServiceAddon;
use App\Filament\Resources\ServiceAddons\Schemas\ServiceAddonForm;
use App\Filament\Resources\ServiceAddons\Schemas\ServiceAddonInfolist;
use App\Filament\Resources\ServiceAddons\Tables\ServiceAddonsTable;
use App\Models\ServiceAddon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class ServiceAddonResource extends Resource
{
    protected static ?string $model = ServiceAddon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?int $navigationSort = 22;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.service_addons.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.service_addons.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return ServiceAddonForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServiceAddonInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAddonsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('pricing.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('pricing.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('pricing.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('pricing.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceAddons::route('/'),
            'create' => CreateServiceAddon::route('/create'),
            'view' => ViewServiceAddon::route('/{record}'),
            'edit' => EditServiceAddon::route('/{record}/edit'),
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
