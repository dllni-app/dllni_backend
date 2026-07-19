<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Tables;

use App\Models\PlatformCoupon;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

final class PlatformCouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('الرمز')->searchable()->copyable()->sortable(),
                TextColumn::make('title_ar')->label('العنوان')->searchable()->limit(35),
                TextColumn::make('section')->label('القسم')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    PlatformCoupon::SECTION_CLEANING => 'التنظيف',
                    PlatformCoupon::SECTION_RESTAURANT => 'المطاعم',
                    PlatformCoupon::SECTION_SUPERMARKET => 'السوبر ماركت',
                    PlatformCoupon::SECTION_ALL => 'الكل',
                    default => $state,
                }),
                TextColumn::make('discount_value')->label('الخصم')->formatStateUsing(
                    fn ($state, PlatformCoupon $record): string => $record->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE
                        ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.').' %'
                        : number_format((float) $state, 2)
                ),
                TextColumn::make('audience_type')->label('المستفيدون')->badge()->formatStateUsing(
                    fn (string $state): string => $state === PlatformCoupon::AUDIENCE_ALL_USERS ? 'جميع المستخدمين' : 'محددون'
                ),
                TextColumn::make('used_count')->label('الاستخدامات')->sortable(),
                TextColumn::make('expires_at')->label('تاريخ الانتهاء')->dateTime('Y-m-d H:i')->placeholder('بدون انتهاء')->sortable(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('section')->label('القسم')->options([
                    PlatformCoupon::SECTION_CLEANING => 'التنظيف',
                    PlatformCoupon::SECTION_RESTAURANT => 'المطاعم',
                    PlatformCoupon::SECTION_SUPERMARKET => 'السوبر ماركت',
                    PlatformCoupon::SECTION_ALL => 'جميع الأقسام',
                ]),
                SelectFilter::make('audience_type')->label('المستفيدون')->options([
                    PlatformCoupon::AUDIENCE_ALL_USERS => 'جميع المستخدمين',
                    PlatformCoupon::AUDIENCE_SPECIFIC_USERS => 'مستخدمون محددون',
                ]),
                TernaryFilter::make('is_active')->label('الحالة'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')->requiresConfirmation()
                    ->hidden(fn (PlatformCoupon $record): bool => $record->redemptions()->exists()),
            ])
            ->emptyStateHeading('لا توجد كوبونات')
            ->emptyStateDescription('أنشئ كوبوناً عاماً أو موجهاً لقسم ومستخدمين محددين.');
    }
}
