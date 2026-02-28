<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings;

use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\CreateCleaningTimeWarning;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\EditCleaningTimeWarning;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\ListCleaningTimeWarnings;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\ViewCleaningTimeWarning;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningForm;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Tables\CleaningTimeWarningsTable;
use Modules\Cleaning\Models\CleaningTimeWarning;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CleaningTimeWarningResource extends Resource
{
    protected static ?string $model = CleaningTimeWarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Time-End Warnings';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return CleaningTimeWarningForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningTimeWarningInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningTimeWarningsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningTimeWarnings::route('/'),
            'create' => CreateCleaningTimeWarning::route('/create'),
            'view' => ViewCleaningTimeWarning::route('/{record}'),
            'edit' => EditCleaningTimeWarning::route('/{record}/edit'),
        ];
    }
}
