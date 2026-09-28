<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOffers;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\RestaurantOffers\Pages\ListRestaurantOffers;
use App\Filament\Resources\RestaurantOffers\Pages\ViewRestaurantOffer;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Offer;
use UnitEnum;

final class RestaurantOfferResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?string $navigationLabel = 'عروض المطاعم';

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('العرض')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('discount_type')->label('نوع الخصم')->badge()
                    ->formatStateUsing(fn ($state): string => self::discountTypeLabel($state)),
                TextColumn::make('discount_value')->label('قيمة الخصم')->sortable(),
                TextColumn::make('products_count')->counts('products')->label('منتجات مشمولة')->sortable(),
                TextColumn::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                TextColumn::make('urgency')->label('الحالة الزمنية')
                    ->state(fn (Offer $record): string => $record->ends_at?->isPast() ? 'منتهي' : ($record->listingUrgencyTag()?->value ?? 'نشط'))
                    ->badge(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('restaurant')->withCount('products'))
            ->filters([
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
                SelectFilter::make('is_active')->label('فعال')->options([1 => 'نعم', 0 => 'لا']),
                Filter::make('active_now')->label('فعال الآن')->query(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))),
                Filter::make('expiring_soon')->label('ينتهي خلال 3 أيام')->query(fn (Builder $query): Builder => $query
                    ->where('is_active', true)->whereBetween('ends_at', [now(), now()->addDays(3)])),
            ])
            ->recordActions([\Filament\Actions\ViewAction::make()])
            ->defaultSort('ends_at', 'asc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات العرض')->schema([
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('restaurant.name')->label('المطعم'),
                TextEntry::make('discount_type')->label('نوع الخصم')->formatStateUsing(fn ($state): string => self::discountTypeLabel($state))->badge(),
                TextEntry::make('discount_value')->label('قيمة الخصم'),
                IconEntry::make('is_active')->label('فعال')->boolean(),
                TextEntry::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('المنتجات المشمولة')->schema([
                RepeatableEntry::make('products')->label('')
                    ->schema([
                        TextEntry::make('name')->label('المنتج'),
                        TextEntry::make('price')->label('السعر')->money(config('app.currency', 'SYP')),
                        TextEntry::make('stock_quantity')->label('المخزون'),
                        IconEntry::make('is_available')->label('متاح')->boolean(),
                    ])->columns(4),
            ])->visible(fn (Offer $record): bool => $record->products()->exists()),
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
        return ['index' => ListRestaurantOffers::route('/'), 'view' => ViewRestaurantOffer::route('/{record}')];
    }

    private static function discountTypeLabel(mixed $state): string
    {
        $value = $state instanceof DiscountType ? $state->value : (string) $state;

        return match ($value) {
            'percentage' => 'نسبة مئوية','fixed_amount' => 'مبلغ ثابت',default => $value ?: '—'
        };
    }
}
