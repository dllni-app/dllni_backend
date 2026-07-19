<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons;

use App\Filament\Resources\PlatformCoupons\Pages\CreatePlatformCoupon;
use App\Filament\Resources\PlatformCoupons\Pages\EditPlatformCoupon;
use App\Filament\Resources\PlatformCoupons\Pages\ListPlatformCoupons;
use App\Filament\Resources\PlatformCoupons\RelationManagers\RedemptionsRelationManager;
use App\Filament\Resources\PlatformCoupons\Schemas\PlatformCouponForm;
use App\Filament\Resources\PlatformCoupons\Tables\PlatformCouponsTable;
use App\Models\PlatformCoupon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class PlatformCouponResource extends Resource
{
    protected static ?string $model = PlatformCoupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return __('restaurant_admin.general_sections');
    }

    public static function getNavigationLabel(): string
    {
        return 'إدارة الكوبونات';
    }

    public static function getModelLabel(): string
    {
        return 'كوبون';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الكوبونات';
    }

    public static function form(Schema $schema): Schema
    {
        return PlatformCouponForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformCouponsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RedemptionsRelationManager::class,
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canViewAny() && ! $record->redemptions()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformCoupons::route('/'),
            'create' => CreatePlatformCoupon::route('/create'),
            'edit' => EditPlatformCoupon::route('/{record}/edit'),
        ];
    }
}
