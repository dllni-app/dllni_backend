<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Schemas;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Support\AdminDeliveryTracking;
use App\Filament\Support\ArabicDashboardLabels;
use App\Filament\Support\SupportCaseInfolistSection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;

final class SmOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('ملخص الطلب')
                    ->schema([
                        TextEntry::make('order_number')->label('رقم الطلب')->copyable(),
                        TextEntry::make('store.name')->label('المتجر')->placeholder('—'),
                        TextEntry::make('customer.name')->label('العميل')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => self::statusLabel($state?->value ?? $state)),
                        TextEntry::make('fulfillment_type')
                            ->label('نوع التنفيذ')
                            ->state(fn (SmOrder $record): string => $record->deliveryOrder()->exists() ? 'توصيل' : 'استلام من المتجر')
                            ->badge(),
                        TextEntry::make('pickup_scheduled_for')->label('موعد الاستلام/التسليم')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('estimated_preparation_minutes')->label('وقت التحضير المتوقع')->suffix(' دقيقة')->placeholder('—'),
                        TextEntry::make('estimated_ready_at')->label('الجاهزية المتوقعة')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('special_instructions')->label('تعليمات خاصة')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('العميل والعنوان')
                    ->schema([
                        TextEntry::make('customer.phone')->label('هاتف العميل')->copyable()->placeholder('—'),
                        TextEntry::make('customer.email')->label('البريد')->placeholder('—'),
                        TextEntry::make('deliveryOrder.dropoff_address')->label('عنوان التسليم')->placeholder('استلام من المتجر')->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('عناصر الطلب')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product_display')
                                    ->label('المنتج')
                                    ->state(fn (SmOrderItem $record): string => $record->product_name ?: $record->product?->name ?: '—'),
                                TextEntry::make('quantity')->label('الكمية'),
                                TextEntry::make('unit_price')
                                    ->label('سعر الوحدة')
                                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                                TextEntry::make('total_price')
                                    ->label('الإجمالي')
                                    ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (SmOrder $record): bool => $record->items()->exists()),

                Section::make('الملخص المالي')
                    ->description('القيم المالية من snapshot محفوظة على الطلب؛ لا يعاد احتساب الطلب القديم بقواعد اليوم.')
                    ->schema([
                        TextEntry::make('subtotal')
                            ->label('قيمة المنتجات')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                        TextEntry::make('discount_amount')
                            ->label('خصم العميل')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                        TextEntry::make('total_amount')
                            ->label('إجمالي العميل')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->weight('bold'),
                        TextEntry::make('merchant_gross_amount')
                            ->label('قيمة المتجر قبل العمولة')
                            ->formatStateUsing(fn ($state): string => $state === null ? 'طلب تاريخي بدون snapshot' : ArabicDashboardLabels::money($state)),
                        TextEntry::make('commission_amount')
                            ->label('عمولة المنصة')
                            ->formatStateUsing(fn ($state): string => $state === null ? 'طلب تاريخي بدون snapshot' : ArabicDashboardLabels::money($state))
                            ->badge()
                            ->color(fn ($state): string => $state === null ? 'warning' : 'info'),
                        TextEntry::make('merchant_net_amount')
                            ->label('صافي مستحق المتجر')
                            ->formatStateUsing(fn ($state): string => $state === null ? 'طلب تاريخي بدون snapshot' : ArabicDashboardLabels::money($state))
                            ->weight('bold'),
                        TextEntry::make('coupon_platform_funded_amount')
                            ->label('تحمل المنصة من الكوبون')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state ?? 0)),
                        TextEntry::make('coupon_merchant_funded_amount')
                            ->label('تحمل المتجر من الكوبون')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state ?? 0)),
                        TextEntry::make('platform_gross_revenue')
                            ->label('إيراد المنصة قبل دعم الكوبون')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state ?? 0)),
                        TextEntry::make('platform_coupon_cost')
                            ->label('تكلفة الكوبون على المنصة')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state ?? 0)),
                        TextEntry::make('platform_net_revenue')
                            ->label('صافي إيراد المنصة')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state ?? 0))
                            ->weight('bold'),
                        TextEntry::make('commission_rule_display')
                            ->label('قاعدة العمولة')
                            ->state(fn (SmOrder $record): string => $record->commission_rule_id === null
                                ? ($record->commission_snapshot === null ? 'طلب قديم بدون snapshot' : 'لا توجد قاعدة فعالة')
                                : '#'.$record->commission_rule_id)
                            ->badge()
                            ->color(fn (SmOrder $record): string => $record->commission_snapshot === null ? 'warning' : 'gray'),
                        TextEntry::make('financial_reversed_at')
                            ->label('عكس التسوية')
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('غير معكوس'),
                        TextEntry::make('service_fee')
                            ->label('رسوم الخدمة')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state)),
                        TextEntry::make('deliveryOrder.delivery_fee')
                            ->label('رسوم التوصيل')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')
                            ->label('رسوم الإلغاء')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->placeholder('—'),
                    ])
                    ->columns(3),

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
                    ->columns(3)
                    ->collapsible(),

                Section::make('الخط الزمني')
                    ->schema([
                        RepeatableEntry::make('statusLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('from_status')->label('من')->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                                TextEntry::make('to_status')->label('إلى')->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                                TextEntry::make('changedByUser.name')->label('تم التغيير بواسطة')->placeholder('النظام'),
                                TextEntry::make('notes')->label('ملاحظة')->placeholder('—'),
                                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(5),
                    ])
                    ->visible(fn (SmOrder $record): bool => $record->statusLogs()->exists()),

                Section::make('دورة حياة الطلب')
                    ->schema([
                        TextEntry::make('accepted_at')->label('وقت القبول')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label('جاهز للاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label('تم الاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('customer_pickup_confirmed_at')->label('تأكيد العميل')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('وقت الإلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancellation_reason')->label('سبب الإلغاء')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('التوصيل والتتبع')
                    ->schema([
                        TextEntry::make('delivery_order_number')
                            ->label('طلب التوصيل')
                            ->state(fn (SmOrder $record): string => $record->deliveryOrder?->order_number ?? '—')
                            ->url(fn (SmOrder $record): ?string => $record->deliveryOrder
                                ? DeliveryOrderResource::getUrl('view', ['record' => $record->deliveryOrder])
                                : null),
                        TextEntry::make('deliveryOrder.status')
                            ->label('حالة التوصيل')
                            ->badge()
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? __('delivery_company.orders.enums.status.'.$state)
                                : '—'),
                        TextEntry::make('deliveryOrder.company.name')->label('شركة التوصيل')->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.first_name')->label('المندوب')->placeholder('غير معيّن'),
                        TextEntry::make('deliveryOrder.dispatch_phase')->label('مرحلة الإسناد')->placeholder('—'),
                        TextEntry::make('delivery_tracking_eta')
                            ->label('ETA المستخدم')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::etaText($record->deliveryOrder))
                            ->badge()
                            ->color('info'),
                        TextEntry::make('delivery_tracking_eta_minutes')
                            ->label('الوقت المتوقع')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::etaMinutes($record->deliveryOrder)),
                        TextEntry::make('delivery_tracking_route_distance')
                            ->label('مسافة المسار')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::routeDistance($record->deliveryOrder)),
                        TextEntry::make('delivery_tracking_pickup')
                            ->label('نقطة الاستلام')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::pickup($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('delivery_tracking_dropoff')
                            ->label('نقطة التسليم')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::dropoff($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('delivery_tracking_driver_marker')
                            ->label('موقع المندوب على الخريطة')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::driverMarker($record->deliveryOrder))
                            ->copyable(),
                        TextEntry::make('delivery_tracking_timeline')
                            ->label('مراحل التتبع')
                            ->state(fn (SmOrder $record): string => AdminDeliveryTracking::timelineSummary($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('deliveryOrder.distance_km')->label('المسافة')->suffix(' كم')->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.last_seen_at')->label('آخر ظهور للمندوب')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.latitude')->label('آخر خط عرض')->copyable()->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.longitude')->label('آخر خط طول')->copyable()->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.recorded_at')->label('وقت آخر موقع')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('deliveryOrder.stop_reason')->label('سبب توقف التوصيل')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->visible(fn (SmOrder $record): bool => $record->deliveryOrder()->exists()),

                Section::make('سجل الإرجاعات')
                    ->schema([
                        RepeatableEntry::make('returnInventoryLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.name')->label('المنتج')->placeholder('—'),
                                TextEntry::make('quantity_change')->label('الكمية المرجعة'),
                                TextEntry::make('quantity_after')->label('المخزون بعد الإرجاع'),
                                TextEntry::make('user.name')->label('نفذ العملية')->placeholder('النظام'),
                                TextEntry::make('notes')->label('السبب/الملاحظة')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('created_at')->label('وقت الإرجاع')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (SmOrder $record): bool => $record->returnInventoryLogs()->exists()),

                Section::make('النزاعات')
                    ->schema([
                        RepeatableEntry::make('disputes')
                            ->label('')
                            ->schema([
                                TextEntry::make('ticket_number')->label('رقم النزاع')->copyable(),
                                TextEntry::make('status')
                                    ->label('الحالة')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state?->value ?? (string) $state),
                                TextEntry::make('reason')->label('السبب')->placeholder('—'),
                                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('resolution_notes')->label('قرار الإدارة')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn (SmOrder $record): bool => $record->disputes()->exists()),

                SupportCaseInfolistSection::make(),

                Section::make('سياسة الإلغاء')
                    ->schema([
                        TextEntry::make('cancellationPolicy.name')->label('السياسة')->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')
                            ->label('رسوم الإلغاء')
                            ->formatStateUsing(fn ($state): string => ArabicDashboardLabels::money($state))
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        $key = 'supermarket_admin.enums.order_status.'.$status;
        $translated = __($key);

        return $translated === $key ? Str::headline($status) : $translated;
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
        return mb_rtrim(mb_rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
