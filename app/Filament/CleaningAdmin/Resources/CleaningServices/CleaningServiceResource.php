<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningServices;

use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\CreateCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\EditCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\ListCleaningServices;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\ViewCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\RelationManagers\PricingRelationManager;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Schemas\CleaningServiceForm;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Schemas\CleaningServiceInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Tables\CleaningServicesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningService;
use UnitEnum;

final class CleaningServiceResource extends Resource
{
    protected static ?string $model = CleaningService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'خدمات التنظيف';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 10;

    public static function getNavigationTooltip(): ?string
    {
        return 'تعريف أنواع خدمات التنظيف: الاسم، الوصف، ربط التسعير الأساسي والإضافات المتاحة.';
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
