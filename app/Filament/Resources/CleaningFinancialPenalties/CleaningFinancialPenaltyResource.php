<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties;

use App\Filament\Resources\CleaningFinancialPenalties\Pages\ListCleaningFinancialPenalties;
use App\Filament\Resources\CleaningFinancialPenalties\Pages\ViewCleaningFinancialPenalty;
use App\Models\CleaningFinancialPenalty;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class CleaningFinancialPenaltyResource extends Resource
{
    protected static ?string $model = CleaningFinancialPenalty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 38;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'الغرامات المالية';
    }

    public static function getModelLabel(): string
    {
        return 'غرامة مالية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الغرامات المالية';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return Tables\CleaningFinancialPenaltiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningFinancialPenalties::route('/'),
            'view' => ViewCleaningFinancialPenalty::route('/{record}'),
        ];
    }
}
