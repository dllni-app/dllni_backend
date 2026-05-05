<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\MasterProductCategories\Pages\CreateMasterProductCategory;
use App\Filament\Resources\MasterProductCategories\Pages\EditMasterProductCategories;
use App\Filament\Resources\MasterProductCategories\Pages\ListMasterProductCategories;
use App\Filament\Resources\MasterProductCategories\Pages\ViewMasterProductCategory;
use App\Filament\Resources\MasterProductCategories\Schemas\MasterProductCategoryForm;
use App\Filament\Resources\MasterProductCategories\Schemas\MasterProductCategoryInfolist;
use App\Filament\Resources\MasterProductCategories\Tables\MasterProductCategoriesTable;
use App\Models\MasterProductCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class MasterProductCategoryResource extends Resource
{
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = MasterProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 6;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.master_product_categories');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.master_product_categories');
    }

    public static function form(Schema $schema): Schema
    {
        return MasterProductCategoryForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MasterProductCategoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MasterProductCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMasterProductCategories::route('/'),
            'create' => CreateMasterProductCategory::route('/create'),
            'view' => ViewMasterProductCategory::route('/{record}'),
            'edit' => EditMasterProductCategories::route('/{record}/edit'),
        ];
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof MasterProductCategory) {
            return null;
        }

        return $record->name;
    }
}
