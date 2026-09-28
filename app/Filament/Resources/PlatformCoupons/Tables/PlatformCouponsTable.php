<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Tables;

use App\Jobs\DispatchPlatformCouponNotifications;
use App\Models\PlatformCoupon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('funding_type')
                    ->label('تمويل الخصم')
                    ->badge()
                    ->formatStateUsing(fn (string $state, PlatformCoupon $record): string => $record->section === PlatformCoupon::SECTION_CLEANING
                        ? 'منطق التنظيفات الحالي'
                        : match ($state) {
                            PlatformCoupon::FUNDING_SHARED => 'مشترك',
                            PlatformCoupon::FUNDING_MERCHANT => 'صاحب العمل',
                            default => 'المنصة',
                        }),
                TextColumn::make('used_count')->label('الاستخدامات النشطة')->sortable(),
                TextColumn::make('active_platform_funded_amount')
                    ->label('تكلفة المنصة')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2).' ل.س')
                    ->toggleable(),
                TextColumn::make('active_merchant_funded_amount')
                    ->label('تكلفة أصحاب الأعمال')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0), 2).' ل.س')
                    ->toggleable(),
                TextColumn::make('expires_at')->label('تاريخ الانتهاء')->dateTime('Y-m-d H:i')->placeholder('بدون انتهاء')->sortable(),
                TextColumn::make('notification_sent_at')->label('آخر إرسال')->dateTime('Y-m-d H:i')->placeholder('لم يرسل')->toggleable(),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum([
                    'redemptions as active_platform_funded_amount' => fn (Builder $redemptions): Builder => $redemptions->whereNull('reversed_at'),
                ], 'platform_funded_amount')
                ->withSum([
                    'redemptions as active_merchant_funded_amount' => fn (Builder $redemptions): Builder => $redemptions->whereNull('reversed_at'),
                ], 'merchant_funded_amount'))
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
                Action::make('resend_notifications')
                    ->label('إعادة إرسال الإشعار')
                    ->icon('heroicon-o-bell-alert')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('سيتم إرسال إشعار الكوبون مرة أخرى إلى جميع المستخدمين المؤهلين حالياً.')
                    ->action(function (PlatformCoupon $record): void {
                        $record->forceFill(['notification_sent_at' => null])->saveQuietly();
                        DispatchPlatformCouponNotifications::dispatch((int) $record->id)->afterCommit();

                        Notification::make()
                            ->title('تمت جدولة إعادة إرسال إشعار الكوبون')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()->label('حذف')->requiresConfirmation()
                    ->hidden(fn (PlatformCoupon $record): bool => $record->redemptions()->exists()),
            ])
            ->emptyStateHeading('لا توجد كوبونات')
            ->emptyStateDescription('أنشئ كوبوناً عاماً أو موجهاً لقسم ومستخدمين محددين.');
    }
}
