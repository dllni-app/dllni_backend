<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantInventoryItems;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\RestaurantInventoryItems\Pages\ListRestaurantInventoryItems;
use App\Filament\Resources\RestaurantInventoryItems\Pages\ViewRestaurantInventoryItem;
use App\Filament\Support\AdminRestaurantLabels;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\InventoryItem;

final class RestaurantInventoryItemResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?int $navigationSort = 6;

    protected static ?string $model = InventoryItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'مخزون المطاعم';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::restaurants();
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.restaurant_inventory_item.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.restaurant_inventory_item.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->searchPlaceholder('ابحث باسم المادة أو المطعم')
            ->columns([
                TextColumn::make('name')->label('المادة')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('quantity')->label('الكمية')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('unit')
                    ->label('الوحدة')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => AdminRestaurantLabels::inventoryUnit($state)),
                TextColumn::make('minimum_limit')->label('الحد الأدنى')->numeric(decimalPlaces: 2)->sortable(),
                TextColumn::make('stock_state')->label('الحالة')
                    ->state(fn (InventoryItem $record): string => (float) $record->quantity <= (float) $record->minimum_limit ? 'مخزون منخفض' : 'طبيعي')
                    ->badge()->color(fn (string $state): string => $state === 'طبيعي' ? 'success' : 'warning'),
                TextColumn::make('unit_cost')->label('تكلفة الوحدة')->money(config('app.currency', 'SYP'))->placeholder('—')->toggleable(),
                TextColumn::make('products_count')->counts('products')->label('منتجات مرتبطة')->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('restaurant')->withCount('products'))
            ->filters([
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
                Filter::make('low_stock')->label('مخزون منخفض')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('quantity', '<=', 'minimum_limit')),
            ])
            ->recordActions([\Filament\Actions\ViewAction::make()])
            ->defaultSort('quantity');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المخزون')->schema([
                TextEntry::make('name')->label('المادة'),
                TextEntry::make('restaurant.name')->label('المطعم'),
                TextEntry::make('quantity')->label('الكمية الحالية'),
                TextEntry::make('unit')
                    ->label('الوحدة')
                    ->formatStateUsing(fn ($state): string => AdminRestaurantLabels::inventoryUnit($state)),
                TextEntry::make('minimum_limit')->label('الحد الأدنى'),
                TextEntry::make('unit_cost')->label('تكلفة الوحدة')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextEntry::make('stock_state')->label('الحالة')
                    ->state(fn (InventoryItem $record): string => (float) $record->quantity <= (float) $record->minimum_limit ? 'مخزون منخفض' : 'طبيعي')->badge(),
            ])->columns(3),
            Section::make('المنتجات التي تستخدم هذه المادة')->schema([
                RepeatableEntry::make('products')->label('')
                    ->schema([
                        TextEntry::make('name')->label('المنتج'),
                        TextEntry::make('price')->label('السعر')->money(config('app.currency', 'SYP')),
                        TextEntry::make('pivot.quantity_used')->label('الكمية المستخدمة'),
                    ])->columns(3),
            ])->visible(fn (InventoryItem $record): bool => $record->products()->exists()),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['restaurant', 'products']);
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
        return ['index' => ListRestaurantInventoryItems::route('/'), 'view' => ViewRestaurantInventoryItem::route('/{record}')];
    }
}
