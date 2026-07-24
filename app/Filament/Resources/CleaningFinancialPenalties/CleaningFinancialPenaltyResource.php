<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties;

use App\Filament\Resources\CleaningFinancialPenalties\Pages\ListCleaningFinancialPenalties;
use App\Filament\Resources\CleaningFinancialPenalties\Pages\ViewCleaningFinancialPenalty;
use App\Filament\Resources\CleaningFinancialPenalties\Schemas\CleaningFinancialPenaltyInfolist;
use App\Filament\Resources\CleaningFinancialPenalties\Tables\CleaningFinancialPenaltiesTable;
use App\Models\CleaningFinancialPenalty;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CleaningFinancialPenaltyResource extends Resource
{
    protected static ?string $model = CleaningFinancialPenalty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 53;

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

    public static function table(Table $table): Table
    {
        return CleaningFinancialPenaltiesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningFinancialPenaltyInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'booking.cancelledByWorker.user',
            'worker.user',
            'financialTransaction',
            'appliedByAdmin',
        ]);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningFinancialPenalties::route('/'),
            'view' => ViewCleaningFinancialPenalty::route('/{record}'),
        ];
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
