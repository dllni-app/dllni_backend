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

final class ServiceAddonResource extends Resource
{
    protected static ?string $model = ServiceAddon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_group');
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
}
