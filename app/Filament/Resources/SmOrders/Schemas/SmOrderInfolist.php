<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Schemas;

use App\Filament\Support\ArabicDashboardLabels;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $orderStatusLabels = collect(SmOrderStatus::cases())->mapWithKeys(
            fn ($c) => [$c->value => __('supermarket_admin.enums.order_status.'.$c->value)]
        )->all();

        return $schema
            ->components([
                Section::make(__('supermarket_admin.orders'))
                    ->schema([
                        TextEntry::make('order_number')->label(__('supermarket_admin.infolist.order_number')),
                        TextEntry::make('customer.name')->label(__('supermarket_admin.infolist.order_customer'))->placeholder('—'),
                        TextEntry::make('store.name')->label(__('supermarket_admin.infolist.name'))->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('supermarket_admin.form.status'))
                            ->formatStateUsing(fn ($state) => $state ? ($orderStatusLabels[$state->value] ?? $state->value) : '—')
                            ->badge(),
                        TextEntry::make('pickup_scheduled_for')->label(__('supermarket_admin.infolist.pickup_scheduled'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label(__('supermarket_admin.infolist.ready_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label(__('supermarket_admin.infolist.picked_up_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('customer_pickup_confirmed_at')->label(__('supermarket_admin.infolist.customer_confirmed'))->dateTime('d/m/Y H:i')->placeholder('—'),
                        TextEntry::make('total_amount')
                            ->label(__('supermarket_admin.infolist.total_amount'))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                        TextEntry::make('cancellation_fee_amount')
                            ->label(__('supermarket_admin.infolist.cancellation_fee'))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('الكوبون والخصم')
                    ->schema([
                        TextEntry::make('coupon_used_status')
                            ->label('تم استخدام كوبون؟')
                            ->state(fn (SmOrder $record): string => self::couponUsed($record) ? 'نعم' : 'لا')
                            ->badge()
                            ->color(fn (SmOrder $record): string => self::couponUsed($record) ? 'success' : 'gray'),
                        TextEntry::make('coupon_code_display')
                            ->label('كود الكوبون')
                            ->state(fn (SmOrder $record): string => self::couponCode($record))
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_type_display')
                            ->label('نوع الخصم')
                            ->state(fn (SmOrder $record): string => self::couponTypeLabel($record))
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_value_display')
                            ->label('نسبة/قيمة الكوبون')
                            ->state(fn (SmOrder $record): string => self::couponValueLabel($record))
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_price_before')
                            ->label('تكلفة الطلب قبل الكوبون')
                            ->state(fn (SmOrder $record): float => self::priceBeforeCoupon($record))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_discount_amount')
                            ->label('قيمة الخصم الفعلية')
                            ->state(fn (SmOrder $record): float => (float) ($record->discount_amount ?? 0))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                        TextEntry::make('coupon_price_after')
                            ->label('تكلفة الطلب بعد الكوبون')
                            ->state(fn (SmOrder $record): float => (float) ($record->total_amount ?? 0))
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->weight('bold')
                            ->visible(fn (SmOrder $record): bool => self::couponUsed($record)),
                    ])
                    ->columns(3),
                Section::make(__('supermarket_admin.infolist.status_timeline'))
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('to_status')->label(__('supermarket_admin.form.status')),
                                TextEntry::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('d/m/Y H:i'),
                            ])
                            ->columns(2),
                    ])
                    ->collapsible(),
            ]);
    }

    private static function couponUsed(SmOrder $record): bool
    {
        return filled($record->platform_coupon_code)
            || $record->platform_coupon_id !== null
            || $record->coupon_id !== null
            || (float) ($record->discount_amount ?? 0) > 0;
    }

    private static function couponCode(SmOrder $record): string
    {
        return (string) ($record->platform_coupon_code
            ?: $record->platformCoupon?->code
            ?: $record->coupon?->code
            ?: '—');
    }

    private static function couponType(SmOrder $record): ?string
    {
        if (filled($record->platform_coupon_code) || $record->platform_coupon_id !== null) {
            return $record->platformCoupon?->discount_type;
        }

        return $record->coupon?->type;
    }

    private static function couponTypeLabel(SmOrder $record): string
    {
        return match (self::couponType($record)) {
            'percentage' => 'نسبة مئوية',
            'fixed', 'fixed_amount' => 'مبلغ ثابت',
            default => '—',
        };
    }

    private static function couponValue(SmOrder $record): ?float
    {
        if (filled($record->platform_coupon_code) || $record->platform_coupon_id !== null) {
            return $record->platformCoupon?->discount_value !== null
                ? (float) $record->platformCoupon->discount_value
                : null;
        }

        if ($record->coupon === null) {
            return null;
        }

        return $record->coupon->type === 'percentage'
            ? ($record->coupon->percent !== null ? (float) $record->coupon->percent : null)
            : ($record->coupon->value !== null ? (float) $record->coupon->value : null);
    }

    private static function couponValueLabel(SmOrder $record): string
    {
        $value = self::couponValue($record);
        if ($value === null) {
            return '—';
        }

        if (self::couponType($record) === 'percentage') {
            return self::formatNumber($value).'%';
        }

        return ArabicDashboardLabels::money($value);
    }

    private static function priceBeforeCoupon(SmOrder $record): float
    {
        return max(0.0, (float) ($record->total_amount ?? 0) + (float) ($record->discount_amount ?? 0));
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
