<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantFoodCategories;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\RestaurantFoodCategories\Pages\CreateRestaurantFoodCategory;
use App\Filament\Resources\RestaurantFoodCategories\Pages\EditRestaurantFoodCategory;
use App\Filament\Resources\RestaurantFoodCategories\Pages\ListRestaurantFoodCategories;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\FoodDiscoveryCategory;

final class RestaurantFoodCategoryResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = FoodDiscoveryCategory::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;
    protected static ?string $navigationLabel = 'أصناف الطعام العامة';
    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::restaurants();
    }

    public static function getModelLabel(): string
    {
        return 'صنف طعام عام';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أصناف الطعام العامة';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('صنف الطعام')
                ->description('هذه الأصناف مشتركة بين المطاعم وليست تصنيفات المنيو المحلية.')
                ->schema([
                    TextInput::make('slug')->label('الرمز الثابت')->required()->maxLength(80)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?Model $record): bool => $record !== null),
                    TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(120),
                    TextInput::make('name_en')->label('الاسم بالإنجليزية')->maxLength(120),
                    TextInput::make('sort_order')->label('ترتيب العرض')->numeric()->default(0)->minValue(0)->required(),
                    Toggle::make('is_active')->label('فعال للاكتشاف')->default(true),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name_ar')->label('الصنف')->searchable()->sortable(),
            TextColumn::make('name_en')->label('الاسم بالإنجليزية')->toggleable(),
            TextColumn::make('slug')->label('الرمز الثابت')->searchable(),
            TextColumn::make('products_count')->counts('products')->label('المنتجات')->sortable(),
            TextColumn::make('sort_order')->label('الترتيب')->sortable(),
            IconColumn::make('is_active')->label('فعال')->boolean(),
        ])->recordActions([EditAction::make()])->defaultSort('sort_order');
    }

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('restaurant_catalog.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('restaurant_catalog.view');
    }

    public static function canCreate(): bool
    {
        return self::dashboardAllowed('restaurant_catalog.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::dashboardAllowed('restaurant_catalog.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // Keep foreign-key identities stable for all products.
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantFoodCategories::route('/'),
            'create' => CreateRestaurantFoodCategory::route('/create'),
            'edit' => EditRestaurantFoodCategory::route('/{record}/edit'),
        ];
    }
}
