<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Support\AdminDeliveryLabels;
use App\Filament\Support\AdminDeliveryTracking;
use App\Filament\Support\SupportCaseInfolistSection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Modules\Resturants\Models\Order;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('ملخص الطلب')
                    ->schema([
                        TextEntry::make('order_number')->label('رقم الطلب')->copyable(),
                        TextEntry::make('restaurant.name')->label('المطعم')->placeholder('—'),
                        TextEntry::make('user.name')->label('العميل')->placeholder('—'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => self::statusLabel($state?->value ?? $state)),
                        TextEntry::make('order_type')
                            ->label('نوع التنفيذ')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => self::orderTypeLabel($state?->value ?? $state)),
                        TextEntry::make('assignedStaff.name')->label('الموظف المسؤول')->placeholder('—'),
                        TextEntry::make('estimated_preparation_minutes')->label('وقت التحضير المتوقع')->suffix(' دقيقة')->placeholder('—'),
                        TextEntry::make('estimated_ready_at')->label('الجاهزية المتوقعة')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('special_instructions')->label('تعليمات العميل')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('kitchen_notes')->label('ملاحظات المطبخ')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('العميل والعنوان')
                    ->schema([
                        TextEntry::make('user.phone')->label('هاتف العميل')->copyable()->placeholder('—'),
                        TextEntry::make('user.email')->label('البريد')->placeholder('—'),
                        TextEntry::make('customer_address')
                            ->label('عنوان التوصيل')
                            ->state(fn (Order $record): string => self::customerAddress($record))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('عناصر الطلب')
                    ->schema([
                        RepeatableEntry::make('orderItems')
                            ->label('')
                            ->schema([
                                TextEntry::make('product.name')->label('المنتج')->placeholder('—'),
                                TextEntry::make('quantity')->label('الكمية'),
                                TextEntry::make('unit_price')->label('سعر الوحدة')->money(config('app.currency', 'SYP')),
                                TextEntry::make('total_price')->label('الإجمالي')->money(config('app.currency', 'SYP')),
                                TextEntry::make('special_instructions')->label('ملاحظات')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Order $record): bool => $record->orderItems()->exists()),

                Section::make('الملخص المالي')
                    ->description('القيم المالية من snapshot محفوظة على الطلب؛ لا يعاد احتساب الطلب القديم بقواعد اليوم.')
                    ->schema([
                        TextEntry::make('subtotal')->label('قيمة المنتجات')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('discount_amount')->label('خصم العميل')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('total_amount')->label('إجمالي العميل')->money(config('app.currency', 'SYP'))->weight('bold'),
                        TextEntry::make('merchant_gross_amount')
                            ->label('قيمة المطعم قبل العمولة')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('طلب تاريخي بدون snapshot'),
                        TextEntry::make('commission_amount')
                            ->label('عمولة المنصة')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('طلب تاريخي بدون snapshot'),
                        TextEntry::make('merchant_net_amount')
                            ->label('صافي مستحق المطعم')
                            ->money(config('app.currency', 'SYP'))
                            ->weight('bold')
                            ->placeholder('طلب تاريخي بدون snapshot'),
                        TextEntry::make('coupon_platform_funded_amount')
                            ->label('تحمل المنصة من الكوبون')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('—'),
                        TextEntry::make('coupon_merchant_funded_amount')
                            ->label('تحمل المطعم من الكوبون')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('—'),
                        TextEntry::make('platform_gross_revenue')
                            ->label('إيراد المنصة قبل دعم الكوبون')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('—'),
                        TextEntry::make('platform_coupon_cost')
                            ->label('تكلفة الكوبون على المنصة')
                            ->money(config('app.currency', 'SYP'))
                            ->placeholder('—'),
                        TextEntry::make('platform_net_revenue')
                            ->label('صافي إيراد المنصة')
                            ->money(config('app.currency', 'SYP'))
                            ->weight('bold')
                            ->placeholder('—'),
                        TextEntry::make('financial_reversed_at')
                            ->label('عكس التسوية')
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('غير معكوس'),
                        TextEntry::make('tax_amount')->label('الضريبة')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('service_fee')->label('رسوم الخدمة')->money(config('app.currency', 'SYP'))->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')->label('رسوم الإلغاء')->money(config('app.currency', 'SYP'))->placeholder('—'),
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
                    ->columns(3)
                    ->collapsible(),

                Section::make('الخط الزمني')
                    ->schema([
                        RepeatableEntry::make('orderStatusLogs')
                            ->label('')
                            ->schema([
                                TextEntry::make('from_status')
                                    ->label('من')
                                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                                TextEntry::make('to_status')
                                    ->label('إلى')
                                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state)),
                                TextEntry::make('note')->label('ملاحظة')->placeholder('—'),
                                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Order $record): bool => $record->orderStatusLogs()->exists()),

                Section::make('دورة حياة الطلب')
                    ->schema([
                        TextEntry::make('accepted_at')->label('وقت القبول')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('preparing_at')->label('بدء التحضير')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label('جاهز للاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label('تم الاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('completed_at')->label('وقت الإكمال')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('وقت الإلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancellation_reason')->label('سبب الإلغاء')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('التوصيل والتتبع')
                    ->schema([
                        TextEntry::make('delivery_order_number')
                            ->label('طلب التوصيل')
                            ->state(fn (Order $record): string => $record->deliveryOrder?->order_number ?? '—')
                            ->url(fn (Order $record): ?string => $record->deliveryOrder
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
                        TextEntry::make('deliveryOrder.dispatch_phase')
                            ->label('مرحلة الإسناد')
                            ->formatStateUsing(fn (?string $state): string => AdminDeliveryLabels::dispatchPhase($state))
                            ->placeholder('—'),
                        TextEntry::make('delivery_tracking_eta')
                            ->label('الوقت المتوقع للوصول المعتمد')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::etaText($record->deliveryOrder))
                            ->badge()
                            ->color('info'),
                        TextEntry::make('delivery_tracking_eta_minutes')
                            ->label('الوقت المتوقع')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::etaMinutes($record->deliveryOrder)),
                        TextEntry::make('delivery_tracking_route_distance')
                            ->label('مسافة المسار')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::routeDistance($record->deliveryOrder)),
                        TextEntry::make('delivery_tracking_pickup')
                            ->label('نقطة الاستلام')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::pickup($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('delivery_tracking_dropoff')
                            ->label('نقطة التسليم')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::dropoff($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('delivery_tracking_driver_marker')
                            ->label('موقع المندوب على الخريطة')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::driverMarker($record->deliveryOrder))
                            ->copyable(),
                        TextEntry::make('delivery_tracking_timeline')
                            ->label('مراحل التتبع')
                            ->state(fn (Order $record): string => AdminDeliveryTracking::timelineSummary($record->deliveryOrder))
                            ->columnSpanFull(),
                        TextEntry::make('deliveryOrder.distance_km')->label('المسافة')->suffix(' كم')->placeholder('—'),
                        TextEntry::make('deliveryOrder.delivery_fee')
                            ->label('رسوم التوصيل')
                            ->money(fn (Order $record): string => $record->deliveryOrder?->currency ?? config('delivery.pricing.default_currency', 'SYP'))
                            ->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.last_seen_at')->label('آخر ظهور للمندوب')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.latitude')->label('آخر خط عرض')->copyable()->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.longitude')->label('آخر خط طول')->copyable()->placeholder('—'),
                        TextEntry::make('deliveryOrder.driver.latestLocation.recorded_at')->label('وقت آخر موقع')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('deliveryOrder.stop_reason')->label('سبب توقف التوصيل')->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->visible(fn (Order $record): bool => $record->deliveryOrder()->exists()),

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
                                TextEntry::make('resolution_type')->label('القرار')->placeholder('—'),
                                TextEntry::make('refund_amount')->label('الاسترداد')->money(config('app.currency', 'SYP'))->placeholder('—'),
                                TextEntry::make('deduction_amount')->label('الخصم')->money(config('app.currency', 'SYP'))->placeholder('—'),
                                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                            ])
                            ->columns(3),
                    ])
                    ->visible(fn (Order $record): bool => $record->disputes()->exists()),

                Section::make('تنبيهات النظام')
                    ->schema([
                        RepeatableEntry::make('systemAlerts')
                            ->label('')
                            ->schema([
                                TextEntry::make('alert_type')
                                    ->label('النوع')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                                TextEntry::make('severity')
                                    ->label('الخطورة')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                                TextEntry::make('status')
                                    ->label('الحالة')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                                TextEntry::make('payload.message')->label('الرسالة')->placeholder('—'),
                                TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Order $record): bool => $record->systemAlerts()->exists()),

                SupportCaseInfolistSection::make(),

                Section::make('سياسة الإلغاء')
                    ->schema([
                        TextEntry::make('cancellationPolicy.name')->label('السياسة')->placeholder('—'),
                        TextEntry::make('cancellation_reason_code')->label('رمز سبب الإلغاء')->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')->label('رسوم الإلغاء')->money(config('app.currency', 'SYP'))->placeholder('—'),
                    ])
                    ->columns(3)
                    ->collapsible(),
            ]);
    }

    private static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '—';
        }

        $key = 'restaurant_admin.enums.order_status.'.$status;
        $translated = __($key);

        return $translated === $key ? Str::headline($status) : $translated;
    }

    private static function orderTypeLabel(?string $type): string
    {
        return match ($type) {
            'delivery' => 'توصيل',
            'pickup' => 'استلام',
            'dine_in' => 'داخل المطعم',
            null, '' => '—',
            default => Str::headline($type),
        };
    }

    private static function customerAddress(Order $record): string
    {
        $address = $record->userAddress;

        if ($address === null) {
            return $record->deliveryOrder?->dropoff_address ?? '—';
        }

        $parts = array_filter([
            $address->city,
            $address->neighborhood,
            $address->street,
            $address->building ? 'بناء '.$address->building : null,
            $address->floor ? 'طابق '.$address->floor : null,
            $address->directions,
        ], fn ($part): bool => filled($part));

        return implode('، ', $parts) ?: '—';
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
        return mb_rtrim(mb_rtrim(number_format($value, 2, '.', ','), '0'), '.');
    }
}
