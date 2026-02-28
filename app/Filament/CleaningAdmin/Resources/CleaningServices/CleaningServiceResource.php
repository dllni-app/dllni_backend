<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningServices;

use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\CreateCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\EditCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\ListCleaningServices;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Pages\ViewCleaningService;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Schemas\CleaningServiceForm;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Schemas\CleaningServiceInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningServices\Tables\CleaningServicesTable;
use Modules\Cleaning\Models\CleaningService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CleaningServiceResource extends Resource
{
    protected static ?string $model = CleaningService::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Cleaning Services';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

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
        return [];
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
