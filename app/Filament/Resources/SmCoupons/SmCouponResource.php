<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\SmCoupons\Pages\EditSmCoupon;
use App\Filament\Resources\SmCoupons\Pages\ListSmCoupons;
use App\Filament\Resources\SmCoupons\Pages\ViewSmCoupon;
use App\Filament\Resources\SmCoupons\Schemas\SmCouponForm;
use App\Filament\Resources\SmCoupons\Schemas\SmCouponInfolist;
use App\Filament\Resources\SmCoupons\Tables\SmCouponsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmCoupon;

final class SmCouponResource extends Resource
{
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmCoupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.coupons');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.coupons');
    }

    public static function form(Schema $schema): Schema
    {
        return SmCouponForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmCouponInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmCouponsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmCoupons::route('/'),
            'view' => ViewSmCoupon::route('/{record}'),
            'edit' => EditSmCoupon::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
