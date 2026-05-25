<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBillingPolicies;

use App\Filament\Resources\CleaningBillingPolicies\Pages\CreateCleaningBillingPolicy;
use App\Filament\Resources\CleaningBillingPolicies\Pages\EditCleaningBillingPolicy;
use App\Filament\Resources\CleaningBillingPolicies\Pages\ListCleaningBillingPolicies;
use App\Filament\Resources\CleaningBillingPolicies\Pages\ViewCleaningBillingPolicy;
use App\Filament\Resources\CleaningBillingPolicies\Schemas\CleaningBillingPolicyForm;
use App\Filament\Resources\CleaningBillingPolicies\Schemas\CleaningBillingPolicyInfolist;
use App\Filament\Resources\CleaningBillingPolicies\Tables\CleaningBillingPoliciesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBillingPolicy;

final class CleaningBillingPolicyResource extends Resource
{
    protected static ?string $model = CleaningBillingPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 23;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.billing_policies.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.billing_policies.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningBillingPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningBillingPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningBillingPoliciesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('pricing.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('pricing.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('pricing.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('pricing.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBillingPolicies::route('/'),
            'create' => CreateCleaningBillingPolicy::route('/create'),
            'view' => ViewCleaningBillingPolicy::route('/{record}'),
            'edit' => EditCleaningBillingPolicy::route('/{record}/edit'),
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
