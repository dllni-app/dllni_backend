<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantPromoCodes;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Resources\RestaurantPromoCodes\Pages\ListRestaurantPromoCodes;
use App\Filament\Resources\RestaurantPromoCodes\Pages\ViewRestaurantPromoCode;
use BackedEnum;
use Filament\Infolists\Components\IconEntry;
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
use Modules\Resturants\Models\PromoCode;
use UnitEnum;

final class RestaurantPromoCodeResource extends Resource
{
    use AuthorizesPlatformAdminResource;

    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected static ?string $navigationLabel = 'كوبونات المطاعم';

    protected static bool $shouldRegisterNavigation = false;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable()->copyable()->sortable(),
                TextColumn::make('restaurant.name')->label('المطعم')->searchable()->sortable(),
                TextColumn::make('discount_type')->label('نوع الخصم')->badge()
                    ->formatStateUsing(fn ($state): string => self::discountTypeLabel($state)),
                TextColumn::make('discount_value')->label('قيمة الخصم'),
                TextColumn::make('min_order_amount')->label('الحد الأدنى للطلب')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextColumn::make('usage_count')->label('الاستخدام')->sortable(),
                TextColumn::make('usage_limit')->label('الحد')->placeholder('∞')->sortable(),
                TextColumn::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—')->toggleable(),
                TextColumn::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—')->sortable(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('restaurant'))
            ->filters([
                SelectFilter::make('restaurant_id')->label('المطعم')->relationship('restaurant', 'name')->searchable()->preload(),
                SelectFilter::make('is_active')->label('فعال')->options([1 => 'نعم', 0 => 'لا']),
                Filter::make('active_now')->label('فعال الآن')->query(fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->where(fn (Builder $q): Builder => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                    ->where(fn (Builder $q): Builder => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->where(fn (Builder $q): Builder => $q->whereNull('usage_limit')->orWhereColumn('usage_count', '<', 'usage_limit'))),
            ])
            ->recordActions([\Filament\Actions\ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الكوبون')->schema([
                TextEntry::make('code')->label('الكود')->copyable(),
                TextEntry::make('restaurant.name')->label('المطعم'),
                TextEntry::make('discount_type')->label('نوع الخصم')->formatStateUsing(fn ($state): string => self::discountTypeLabel($state))->badge(),
                TextEntry::make('discount_value')->label('قيمة الخصم'),
                TextEntry::make('min_order_amount')->label('الحد الأدنى للطلب')->money(config('app.currency', 'SYP'))->placeholder('—'),
                TextEntry::make('usage_count')->label('عدد مرات الاستخدام'),
                TextEntry::make('usage_limit')->label('حد الاستخدام')->placeholder('غير محدود'),
                TextEntry::make('remaining_usage')->label('المتبقي')
                    ->state(fn (PromoCode $record): string => $record->usage_limit === null ? 'غير محدود' : (string) max(0, $record->usage_limit - $record->usage_count)),
                IconEntry::make('is_active')->label('فعال')->boolean(),
                TextEntry::make('starts_at')->label('يبدأ')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('ends_at')->label('ينتهي')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('orders_count')->label('طلبات استخدمت الكوبون')->state(fn (PromoCode $record): int => $record->orders()->count()),
            ])->columns(3),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('restaurant');
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
        return ['index' => ListRestaurantPromoCodes::route('/'), 'view' => ViewRestaurantPromoCode::route('/{record}')];
    }

    private static function discountTypeLabel(mixed $state): string
    {
        $value = $state instanceof DiscountType ? $state->value : (string) $state;

        return match ($value) {
            'percentage' => 'نسبة مئوية','fixed_amount' => 'مبلغ ثابت',default => $value ?: '—'
        };
    }
}
