<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Resturants\Models\Order;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الطلب')
                    ->schema([
                        TextEntry::make('order_number')->label('رقم الطلب'),
                        TextEntry::make('restaurant.name')->label('المطعم'),
                        TextEntry::make('user.name')->label('العميل'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->formatStateUsing(function ($state): string {
                                $value = $state?->value ?? $state;

                                return $value ? __('restaurant_admin.enums.order_status.'.$value) : '—';
                            }),
                        TextEntry::make('order_type')->label('نوع الطلب')->formatStateUsing(fn ($state) => $state?->value ?? $state ?? '—'),
                        TextEntry::make('total_amount')->label('المجموع')->money(config('app.currency', 'SYP')),
                        TextEntry::make('subtotal')->label('المجموع الفرعي')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('discount_amount')->label('الخصم')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('special_instructions')->label('تعليمات خاصة')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('الكوبون والخصم')
                    ->schema([
                        TextEntry::make('coupon_used_status')
                            ->label('تم استخدام كوبون؟')
                            ->state(fn (Order $record): string => self::couponUsed($record) ? 'نعم' : 'لا')
                            ->badge()
                            ->color(fn (Order $record): string => self::couponUsed($record) ? 'success' : 'gray'),
                        TextEntry::make('coupon_code_display')
                            ->label('كود الكوبون')
                            ->state(fn (Order $record): string => self::couponCode($record))
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_type_display')
                            ->label('نوع الخصم')
                            ->state(fn (Order $record): string => self::couponTypeLabel($record))
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_value_display')
                            ->label('نسبة/قيمة الكوبون')
                            ->state(fn (Order $record): string => self::couponValueLabel($record))
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_price_before')
                            ->label('تكلفة الطلب قبل الكوبون')
                            ->state(fn (Order $record): float => self::priceBeforeCoupon($record))
                            ->money(config('app.currency', 'SYP'))
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_discount_amount')
                            ->label('قيمة الخصم الفعلية')
                            ->state(fn (Order $record): float => (float) ($record->discount_amount ?? 0))
                            ->money(config('app.currency', 'SYP'))
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_price_after')
                            ->label('تكلفة الطلب بعد الكوبون')
                            ->state(fn (Order $record): float => (float) ($record->total_amount ?? 0))
                            ->money(config('app.currency', 'SYP'))
                            ->weight('bold')
                            ->visible(fn (Order $record): bool => self::couponUsed($record)),
                    ])
                    ->columns(3),
                Section::make('دورة حياة الطلب')
                    ->schema([
                        TextEntry::make('accepted_at')->label('وقت القبول')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('preparing_at')->label('وقت بدء التحضير')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label('جاهز للاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label('تم الاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('completed_at')->label('وقت الإكمال')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('وقت الإلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancellation_reason')->label('سبب الإلغاء')->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('سياسة الإلغاء')
                    ->schema([
                        TextEntry::make('cancellationPolicy.name')->label('السياسة')->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')->label('رسوم الإلغاء')->money(config('app.currency', 'SYP'))->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    private static function couponUsed(Order $record): bool
    {
        return filled($record->platform_coupon_code)
            || $record->platform_coupon_id !== null
            || $record->promo_code_id !== null
            || (float) ($record->discount_amount ?? 0) > 0;
    }

    private static function couponCode(Order $record): string
    {
        return (string) ($record->platform_coupon_code
            ?: $record->platformCoupon?->code
            ?: $record->promoCode?->code
            ?: '—');
    }

    private static function couponType(Order $record): ?string
    {
        if (filled($record->platform_coupon_code) || $record->platform_coupon_id !== null) {
            return $record->platformCoupon?->discount_type;
        }

        return $record->promoCode?->discount_type?->value ?? $record->promoCode?->discount_type;
    }

    private static function couponTypeLabel(Order $record): string
    {
        return match (self::couponType($record)) {
            'percentage' => 'نسبة مئوية',
            'fixed', 'fixed_amount' => 'مبلغ ثابت',
            default => '—',
        };
    }

    private static function couponValue(Order $record): ?float
    {
        if (filled($record->platform_coupon_code) || $record->platform_coupon_id !== null) {
            return $record->platformCoupon?->discount_value !== null
                ? (float) $record->platformCoupon->discount_value
                : null;
        }

        return $record->promoCode?->discount_value !== null
            ? (float) $record->promoCode->discount_value
            : null;
    }

    private static function couponValueLabel(Order $record): string
    {
        $value = self::couponValue($record);
        if ($value === null) {
            return '—';
        }

        if (self::couponType($record) === 'percentage') {
            return self::formatNumber($value).'%';
        }

        return self::formatNumber($value).' '.config('app.currency', 'SYP');
    }

    private static function priceBeforeCoupon(Order $record): float
    {
        return max(0.0, (float) ($record->total_amount ?? 0) + (float) ($record->discount_amount ?? 0));
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
