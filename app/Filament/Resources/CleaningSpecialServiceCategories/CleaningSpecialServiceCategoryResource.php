<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServiceCategories;

use App\Filament\Clusters\CleaningCatalogCluster;
use App\Filament\Resources\CleaningSpecialServiceCategories\Pages\CreateCleaningSpecialServiceCategory;
use App\Filament\Resources\CleaningSpecialServiceCategories\Pages\EditCleaningSpecialServiceCategory;
use App\Filament\Resources\CleaningSpecialServiceCategories\Pages\ListCleaningSpecialServiceCategories;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningSpecialServiceCategory;

final class CleaningSpecialServiceCategoryResource extends Resource
{
    protected static ?string $model = CleaningSpecialServiceCategory::class;

    protected static ?string $cluster = CleaningCatalogCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 29;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.categories');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.categories');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('cleaning_catalog.materials.fields.name'))->required(),
            Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('cleaning_catalog.materials.fields.name'))->searchable()->sortable(),
            TextColumn::make('services_count')->counts('services')->label(__('cleaning_catalog.materials.fields.services')),
            IconColumn::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->boolean(),
        ])->defaultSort('sort_order')->reorderable('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningSpecialServiceCategories::route('/'), 'create' => CreateCleaningSpecialServiceCategory::route('/create'), 'edit' => EditCleaningSpecialServiceCategory::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::allowed('pricing.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::allowed('pricing.update');
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
