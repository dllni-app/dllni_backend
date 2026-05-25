<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAutomationRules;

use App\Filament\Resources\CleaningAutomationRules\Pages\CreateCleaningAutomationRule;
use App\Filament\Resources\CleaningAutomationRules\Pages\EditCleaningAutomationRule;
use App\Filament\Resources\CleaningAutomationRules\Pages\ListCleaningAutomationRules;
use App\Filament\Resources\CleaningAutomationRules\Pages\ViewCleaningAutomationRule;
use App\Filament\Resources\CleaningAutomationRules\Schemas\CleaningAutomationRuleForm;
use App\Filament\Resources\CleaningAutomationRules\Schemas\CleaningAutomationRuleInfolist;
use App\Filament\Resources\CleaningAutomationRules\Tables\CleaningAutomationRulesTable;
use App\Models\CleaningAutomationRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class CleaningAutomationRuleResource extends Resource
{
    protected static ?string $model = CleaningAutomationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 25;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.automation.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.automation.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningAutomationRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningAutomationRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningAutomationRulesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('settings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('settings.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('settings.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('settings.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('settings.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningAutomationRules::route('/'),
            'create' => CreateCleaningAutomationRule::route('/create'),
            'view' => ViewCleaningAutomationRule::route('/{record}'),
            'edit' => EditCleaningAutomationRule::route('/{record}/edit'),
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
