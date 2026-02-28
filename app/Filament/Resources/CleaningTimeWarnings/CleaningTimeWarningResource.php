<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings;

use App\Filament\Resources\CleaningTimeWarnings\Pages\ListCleaningTimeWarnings;
use App\Filament\Resources\CleaningTimeWarnings\Pages\ViewCleaningTimeWarning;
use App\Filament\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningForm;
use App\Filament\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningInfolist;
use App\Filament\Resources\CleaningTimeWarnings\Tables\CleaningTimeWarningsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningResource extends Resource
{
    protected static ?string $model = CleaningTimeWarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 8;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.time_warnings.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.time_warnings.tooltip');
    }

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
            'view' => ViewCleaningTimeWarning::route('/{record}'),
        ];
    }
}
