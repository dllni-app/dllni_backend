<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages\CreateServiceAddon;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages\EditServiceAddon;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages\ListServiceAddons;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages\ViewServiceAddon;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Schemas\ServiceAddonForm;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Schemas\ServiceAddonInfolist;
use App\Filament\CleaningAdmin\Resources\ServiceAddons\Tables\ServiceAddonsTable;
use App\Models\ServiceAddon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ServiceAddonResource extends Resource
{
    protected static ?string $model = ServiceAddon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?string $navigationLabel = 'إضافات الخدمة';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 11;

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة الإضافات الاختيارية: الاسم، الوصف، نوع التسعير (ثابت أو نسبة مئوية من تكلفة الخدمة).';
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
