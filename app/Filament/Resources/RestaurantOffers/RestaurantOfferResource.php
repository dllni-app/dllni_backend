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

final class RestaurantOfferResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?int $navigationSort = 7;

    protected static ?string $model = Offer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $navigationLabel = 'عروض المطاعم';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::restaurants();
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.restaurant_offer.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.restaurant_offer.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('العرض')->searchable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('offer_type')->label('نوع العرض')->badge()->formatStateUsing(fn ($state): string => $state?->value === 'buy_x_get_y' ? 'اشترِ واحصل' : 'خصم منتج'),
                TextColumn::make('rule')->label('شروط العرض')->state(fn (Offer $record): string => $record->isProductDiscount() ? self::discountTypeLabel($record->discount_type).' — '.$record->discount_value : ($record->listingBadgeText() ?? '—')),
                TextColumn::make('promotion_applications_count')->label('الطلبات المسجلة')->state(fn (Offer $record): string => $record->isProductDiscount() ? 'غير متاح' : (string) $record->promotion_applications_count),
                TextColumn::make('products_count')->counts('products')->label('منتجات مشمولة')->sortable(),
                TextColumn::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                TextColumn::make('urgency')->label('الحالة الزمنية')
                    ->state(fn (Offer $record): string => match ($record->effectiveStatus()) { 'paused' => 'متوقف', 'expired' => 'منتهي', 'scheduled' => 'مجدول', default => 'نشط' })
                    ->badge(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('restaurant')->withCount(['products', 'promotionApplications' => fn ($q) => $q->where('status', 'applied')]))
            ->filters([
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
                SelectFilter::make('is_active')->label('فعال')->options([1 => 'نعم', 0 => 'لا']),
                SelectFilter::make('offer_type')->label('نوع العرض')->options(['product_discount' => 'خصم منتج', 'buy_x_get_y' => 'اشترِ واحصل']),
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
                TextEntry::make('discount_type')->label('نوع الخصم')->formatStateUsing(fn ($state): string => self::discountTypeLabel($state))->badge()->visible(fn (Offer $record) => $record->isProductDiscount()),
                TextEntry::make('discount_value')->label('قيمة الخصم')->visible(fn (Offer $record) => $record->isProductDiscount()),
                IconEntry::make('is_active')->label('فعال')->boolean(),
                TextEntry::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('شروط العرض المركب')->schema([
                TextEntry::make('buy_quantity')->label('الكمية المطلوبة'),
                TextEntry::make('reward_quantity')->label('كمية المكافأة'),
                TextEntry::make('reward_type')->label('نوع المكافأة')->formatStateUsing(fn ($state): string => match ($state?->value) { 'same_product' => 'نفس المنتج', 'cheapest_eligible' => 'الأرخص المؤهل', 'specific_products' => 'منتجات محددة', default => '—' }),
                TextEntry::make('reward_discount_percent')->label('نسبة خصم المكافأة')->suffix('%'),
                IconEntry::make('is_repeatable')->label('قابل للتكرار')->boolean(),
                TextEntry::make('max_applications_per_order')->label('الحد لكل طلب')->placeholder('غير محدد'),
                TextEntry::make('rule_version')->label('إصدار القاعدة'),
                TextEntry::make('rewardProducts.name')->label('منتجات المكافأة')->badge()->placeholder('حسب القاعدة'),
            ])->columns(3)->visible(fn (Offer $record): bool => ! $record->isProductDiscount()),
            Section::make('سجل الاستردادات')->schema([
                TextEntry::make('recorded_orders')->label('الطلبات المستفيدة')->state(fn (Offer $record) => $record->promotionApplications()->where('status', 'applied')->distinct()->count('order_id')),
                TextEntry::make('recorded_savings')->label('إجمالي التوفير')->state(fn (Offer $record) => $record->promotionApplications()->where('status', 'applied')->sum('discount_amount'))->money(config('app.currency', 'SYP')),
                TextEntry::make('recorded_rewards')->label('القطع المجانية')->state(fn (Offer $record) => $record->promotionApplications()->where('status', 'applied')->sum('free_quantity')),
                RepeatableEntry::make('recent_redemptions')->label('آخر 50 عملية')->state(fn (Offer $record) => $record->promotionApplications()->latest('applied_at')->limit(50)->get())->schema([
                    TextEntry::make('order_id')->label('الطلب'), TextEntry::make('status')->label('الحالة'), TextEntry::make('discount_amount')->label('التوفير'), TextEntry::make('applied_at')->label('التاريخ')->dateTime('Y-m-d H:i'), TextEntry::make('rule_version')->label('الإصدار'),
                ])->columns(5)->columnSpanFull(),
            ])->columns(3)->visible(fn (Offer $record): bool => ! $record->isProductDiscount()),
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
        return parent::getEloquentQuery()->with(['restaurant', 'products', 'rewardProducts']);
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