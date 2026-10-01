<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantProducts;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\RestaurantProducts\Pages\ListRestaurantProducts;
use App\Filament\Resources\RestaurantProducts\Pages\ViewRestaurantProduct;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\Product;

final class RestaurantProductResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?int $navigationSort = 5;

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = 'منتجات المطاعم';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::restaurants();
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.restaurant_product.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.restaurant_product.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ابحث باسم المنتج أو المطعم')
            ->columns([
                ImageColumn::make('image')->label('')->circular()
                    ->getStateUsing(fn (Product $record): ?string => $record->getFirstMediaUrl('primary-image') ?: null),
                TextColumn::make('name')->label('المنتج')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('category.name')->label('التصنيف')->placeholder('—')->toggleable(),
                TextColumn::make('price')->label('السعر')->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('effective_price')->label('السعر الفعلي')
                    ->state(fn (Product $record): float => $record->effectivePrice())
                    ->money(config('app.currency', 'SYP')),
                TextColumn::make('stock_quantity')->label('المخزون')->sortable(),
                TextColumn::make('low_stock_threshold')->label('حد التنبيه')->sortable()->toggleable(),
                IconColumn::make('is_available')->label('متاح')->boolean(),
                TextColumn::make('availability_mode')->label('حالة التوفر')
                    ->state(fn (Product $record): string => $record->availabilityMode())->badge(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['restaurant', 'category', 'media', 'offers']))
            ->filters([
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
                Filter::make('low_stock')->label('مخزون منخفض')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold')),
                Filter::make('unavailable')->label('غير متاح')
                    ->query(fn (Builder $query): Builder => $query->where('is_available', false)),
            ])
            ->recordActions([\Filament\Actions\ViewAction::make()])
            ->defaultSort('updated_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المنتج')->schema([
                ImageEntry::make('image')->label('الصورة')->circular()
                    ->getStateUsing(fn (Product $record): ?string => $record->getFirstMediaUrl('primary-image') ?: null),
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('restaurant.name')->label('المطعم'),
                TextEntry::make('category.name')->label('التصنيف')->placeholder('—'),
                TextEntry::make('price')->label('السعر')->money(config('app.currency', 'SYP')),
                TextEntry::make('discounted_price')->label('سعر الخصم المباشر')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextEntry::make('effective_price')->label('السعر الفعلي')->state(fn (Product $record): float => $record->effectivePrice())->money(config('app.currency', 'SYP')),
                IconEntry::make('is_available')->label('متاح')->boolean(),
                TextEntry::make('unavailable_until')->label('غير متاح حتى')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('availability_note')->label('ملاحظة التوفر')->placeholder('—')->columnSpanFull(),
                TextEntry::make('stock_quantity')->label('المخزون'),
                TextEntry::make('low_stock_threshold')->label('حد المخزون المنخفض'),
                TextEntry::make('preparation_time')->label('وقت التحضير')->suffix(' دقيقة')->placeholder('—'),
                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('المخزون المرتبط')->schema([
                RepeatableEntry::make('inventoryItems')->label('')
                    ->schema([
                        TextEntry::make('name')->label('المادة'),
                        TextEntry::make('unit')->label('الوحدة'),
                        TextEntry::make('quantity')->label('الكمية الحالية'),
                        TextEntry::make('pivot.quantity_used')->label('الاستهلاك للمنتج'),
                    ])->columns(4),
            ])->visible(fn (Product $record): bool => $record->inventoryItems()->exists()),
            Section::make('العروض المرتبطة')->schema([
                RepeatableEntry::make('offers')->label('')
                    ->schema([
                        TextEntry::make('name')->label('العرض'),
                        TextEntry::make('discount_type')->label('النوع')->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state),
                        TextEntry::make('discount_value')->label('القيمة'),
                        IconEntry::make('is_active')->label('فعال')->boolean(),
                        TextEntry::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
                    ])->columns(5),
            ])->visible(fn (Product $record): bool => $record->offers()->exists()),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['restaurant', 'category', 'media', 'inventoryItems', 'offers']);
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
        return ['index' => ListRestaurantProducts::route('/'), 'view' => ViewRestaurantProduct::route('/{record}')];
    }
}
