<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices;

use App\Filament\Resources\CleaningServices\Pages\CreateCleaningService;
use App\Filament\Resources\CleaningServices\Pages\EditCleaningService;
use App\Filament\Resources\CleaningServices\Pages\ListCleaningServices;
use App\Filament\Resources\CleaningServices\Pages\ViewCleaningService;
use App\Filament\Resources\CleaningServices\RelationManagers\PricingRelationManager;
use App\Filament\Resources\CleaningServices\Schemas\CleaningServiceForm;
use App\Filament\Resources\CleaningServices\Schemas\CleaningServiceInfolist;
use App\Filament\Resources\CleaningServices\Tables\CleaningServicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningService;

final class CleaningServiceResource extends Resource
{
    protected static ?string $model = CleaningService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 21;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.cleaning_services.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.cleaning_services.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningServiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningServiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningServicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PricingRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningServices::route('/'),
            'create' => CreateCleaningService::route('/create'),
            'view' => ViewCleaningService::route('/{record}'),
            'edit' => EditCleaningService::route('/{record}/edit'),
        ];
    }
}
