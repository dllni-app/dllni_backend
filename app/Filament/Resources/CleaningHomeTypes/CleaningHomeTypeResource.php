<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes;

use App\Filament\Resources\CleaningHomeTypes\Pages\CreateCleaningHomeType;
use App\Filament\Resources\CleaningHomeTypes\Pages\EditCleaningHomeType;
use App\Filament\Resources\CleaningHomeTypes\Pages\ListCleaningHomeTypes;
use App\Filament\Resources\CleaningHomeTypes\Schemas\CleaningHomeTypeForm;
use App\Filament\Resources\CleaningHomeTypes\Tables\CleaningHomeTypesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningHomeType;

final class CleaningHomeTypeResource extends Resource
{
    protected static ?string $model = CleaningHomeType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'أنواع واجهة التنظيف';
    }

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة أنواع العقارات والمناسبات والصور المعروضة في تطبيق المستخدم.';
    }

    public static function getModelLabel(): string
    {
        return 'نوع واجهة التنظيف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع واجهة التنظيف';
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningHomeTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningHomeTypesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('cleaning-home-types.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('cleaning-home-types.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('cleaning-home-types.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::hasPermission('cleaning-home-types.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('cleaning-home-types.delete');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningHomeTypes::route('/'),
            'create' => CreateCleaningHomeType::route('/create'),
            'edit' => EditCleaningHomeType::route('/{record}/edit'),
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
