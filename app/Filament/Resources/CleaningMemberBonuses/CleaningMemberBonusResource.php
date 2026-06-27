<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMemberBonuses;

use App\Filament\Resources\CleaningMemberBonuses\Pages\ListCleaningMemberBonuses;
use App\Filament\Resources\CleaningMemberBonuses\Pages\ViewCleaningMemberBonus;
use App\Filament\Resources\CleaningMemberBonuses\Schemas\CleaningMemberBonusInfolist;
use App\Filament\Resources\CleaningMemberBonuses\Tables\CleaningMemberBonusesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningMemberBonus;

final class CleaningMemberBonusResource extends Resource
{
    protected static ?string $model = CleaningMemberBonus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 26;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return 'Member Bonuses';
    }

    public static function getModelLabel(): string
    {
        return 'Member Bonus';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Member Bonuses';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'Review loyalty bonuses created by the automatic rules and activate them manually.';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('settings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('settings.view');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('settings.delete');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningMemberBonusInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningMemberBonusesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningMemberBonuses::route('/'),
            'view' => ViewCleaningMemberBonus::route('/{record}'),
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
