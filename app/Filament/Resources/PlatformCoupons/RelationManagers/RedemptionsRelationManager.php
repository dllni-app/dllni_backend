<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\RelationManagers;

use App\Models\PlatformCoupon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class RedemptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'redemptions';

    protected static ?string $title = 'سجل استخدام الكوبون';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('المستخدم')->searchable()->placeholder('-'),
                TextColumn::make('user.phone')->label('رقم الهاتف')->searchable()->placeholder('-'),
                TextColumn::make('section')->label('القسم')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    PlatformCoupon::SECTION_CLEANING => 'التنظيف',
                    PlatformCoupon::SECTION_RESTAURANT => 'المطاعم',
                    PlatformCoupon::SECTION_SUPERMARKET => 'السوبر ماركت',
                    default => $state,
                }),
                TextColumn::make('order_type')->label('نوع الطلب')->formatStateUsing(
                    fn (?string $state): string => $state ? class_basename($state) : '-'
                )->toggleable(),
                TextColumn::make('order_id')->label('رقم الطلب')->sortable(),
                TextColumn::make('subtotal')->label('المجموع قبل الخصم')->formatStateUsing(
                    fn ($state): string => number_format((float) $state, 2).' ل.س'
                ),
                TextColumn::make('discount_amount')->label('قيمة الخصم')->formatStateUsing(
                    fn ($state): string => number_format((float) $state, 2).' ل.س'
                ),
                TextColumn::make('redeemed_at')->label('تاريخ الاستخدام')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('section')->label('القسم')->options([
                    PlatformCoupon::SECTION_CLEANING => 'التنظيف',
                    PlatformCoupon::SECTION_RESTAURANT => 'المطاعم',
                    PlatformCoupon::SECTION_SUPERMARKET => 'السوبر ماركت',
                ]),
            ])
            ->defaultSort('redeemed_at', 'desc');
    }
}
