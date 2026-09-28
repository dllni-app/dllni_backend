<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmCategories\Pages\CreateSmCategory;
use App\Filament\Resources\SmCategories\Pages\EditSmCategory;
use App\Filament\Resources\SmCategories\Pages\ListSmCategories;
use App\Filament\Resources\SmCategories\Pages\ViewSmCategory;
use App\Filament\Resources\SmCategories\Schemas\SmCategoryForm;
use App\Filament\Resources\SmCategories\Schemas\SmCategoryInfolist;
use App\Filament\Resources\SmCategories\Tables\SmCategoriesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmCategory;

final class SmCategoryResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.categories');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.categories');
    }

    public static function form(Schema $schema): Schema
    {
        return SmCategoryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmCategoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmCategoriesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_catalog.view');
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
        return ['index' => ListSmCategories::route('/'), 'create' => CreateSmCategory::route('/create'), 'view' => ViewSmCategory::route('/{record}'), 'edit' => EditSmCategory::route('/{record}/edit')];
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof SmCategory ? $record->name : null;
    }
}
