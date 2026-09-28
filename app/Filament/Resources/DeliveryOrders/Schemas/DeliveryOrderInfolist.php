<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryOrders\Schemas;

use App\Filament\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Support\SupportCaseInfolistSection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

final class DeliveryOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملخص التوصيل')
                ->schema([
                    TextEntry::make('order_number')->label('رقم طلب التوصيل')->copyable(),
                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => $state
                            ? __('delivery_company.orders.enums.status.'.$state)
                            : '—'),
                    TextEntry::make('company.name')->label('شركة التوصيل')->placeholder('—'),
                    TextEntry::make('driver.first_name')->label('المندوب')->placeholder('غير معيّن'),
                    TextEntry::make('dispatch_phase')->label('مرحلة الإسناد')->placeholder('—'),
                    TextEntry::make('dispatch_wave')->label('موجة الإسناد')->placeholder('—'),
                    TextEntry::make('search_radius_km')->label('نطاق البحث')->suffix(' كم')->placeholder('—'),
                    TextEntry::make('distance_km')->label('المسافة')->suffix(' كم')->placeholder('—'),
                    TextEntry::make('delivery_fee')
                        ->label('رسوم التوصيل')
                        ->money(fn (DeliveryOrder $record): string => $record->currency ?? config('delivery.pricing.default_currency', 'SYP')),
                    TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i'),
                ])
                ->columns(3),

            Section::make('الطلب التجاري المرتبط')
                ->schema([
                    TextEntry::make('source_type')
                        ->label('المصدر')
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'restaurant_order' => 'مطعم',
                            'supermarket_order' => 'سوبرماركت',
                            null, '' => 'طلب توصيل مستقل',
                            default => $state,
                        }),
                    TextEntry::make('source_reference')
                        ->label('رقم الطلب الأصلي')
                        ->state(fn (DeliveryOrder $record): string => (string) ($record->source?->order_number ?? '—'))
                        ->url(fn (DeliveryOrder $record): ?string => self::sourceUrl($record)),
                    TextEntry::make('merchant_status')->label('حالة التاجر')->placeholder('—'),
                    TextEntry::make('estimated_preparation_minutes')->label('وقت التحضير المتوقع')->suffix(' دقيقة')->placeholder('—'),
                    TextEntry::make('estimated_ready_at')->label('الجاهزية المتوقعة')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('merchant_ready_at')->label('وقت جاهزية التاجر')->dateTime('Y-m-d H:i')->placeholder('—'),
                ])
                ->columns(3)
                ->visible(fn (DeliveryOrder $record): bool => $record->source_id !== null),

            Section::make('العميل')
                ->schema([
                    TextEntry::make('customer_name')->label('الاسم'),
                    TextEntry::make('customer_phone')->label('الهاتف')->copyable()->placeholder('—'),
                    TextEntry::make('customer_notes')->label('ملاحظات العميل')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('الاستلام والتسليم')
                ->schema([
                    TextEntry::make('pickup_address')->label('عنوان الاستلام')->columnSpanFull(),
                    TextEntry::make('pickup_latitude')->label('خط عرض الاستلام')->copyable(),
                    TextEntry::make('pickup_longitude')->label('خط طول الاستلام')->copyable(),
                    TextEntry::make('dropoff_address')->label('عنوان التسليم')->columnSpanFull(),
                    TextEntry::make('dropoff_latitude')->label('خط عرض التسليم')->copyable(),
                    TextEntry::make('dropoff_longitude')->label('خط طول التسليم')->copyable(),
                ])
                ->columns(2),

            Section::make('المندوب والتتبع')
                ->schema([
                    TextEntry::make('driver.company.name')->label('شركة المندوب')->placeholder('—'),
                    TextEntry::make('driver.availability_status')->label('حالة المندوب')->badge()->placeholder('—'),
                    TextEntry::make('driver.last_seen_at')->label('آخر ظهور')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('driver.latestLocation.latitude')->label('آخر خط عرض')->copyable()->placeholder('—'),
                    TextEntry::make('driver.latestLocation.longitude')->label('آخر خط طول')->copyable()->placeholder('—'),
                    TextEntry::make('driver.latestLocation.recorded_at')->label('وقت آخر موقع')->dateTime('Y-m-d H:i')->placeholder('—'),
                ])
                ->columns(3)
                ->visible(fn (DeliveryOrder $record): bool => $record->driver_id !== null),

            Section::make('سجل الحالة')
                ->schema([
                    RepeatableEntry::make('events')
                        ->label('')
                        ->schema([
                            TextEntry::make('to_status')->label('الحالة'),
                            TextEntry::make('note')->label('الملاحظة')->placeholder('—'),
                            TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (DeliveryOrder $record): bool => $record->events()->exists()),

            Section::make('محاولات الإسناد')
                ->schema([
                    RepeatableEntry::make('assignmentAttempts')
                        ->label('')
                        ->schema([
                            TextEntry::make('driver.first_name')->label('المندوب')->placeholder('—'),
                            TextEntry::make('status')->label('الحالة')->badge(),
                            TextEntry::make('attempt_no')->label('المحاولة'),
                            TextEntry::make('distance_to_pickup_km')->label('المسافة للاستلام')->suffix(' كم')->placeholder('—'),
                            TextEntry::make('offered_at')->label('وقت العرض')->dateTime('Y-m-d H:i')->placeholder('—'),
                            TextEntry::make('expires_at')->label('انتهاء العرض')->dateTime('Y-m-d H:i')->placeholder('—'),
                            TextEntry::make('reject_reason')->label('سبب الرفض')->placeholder('—'),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (DeliveryOrder $record): bool => $record->assignmentAttempts()->exists()),

            Section::make('نزاعات التوصيل')
                ->schema([
                    RepeatableEntry::make('disputes')
                        ->label('')
                        ->schema([
                            TextEntry::make('ticket_number')
                                ->label('رقم النزاع')
                                ->url(fn ($record): string => DeliveryDisputeResource::getUrl('view', ['record' => $record]))
                                ->copyable(),
                            TextEntry::make('category')
                                ->label('الفئة')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                            TextEntry::make('status')
                                ->label('الحالة')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),
                            TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('created_at')->label('وقت الفتح')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(3),
                ])
                ->visible(fn (DeliveryOrder $record): bool => $record->disputes()->exists()),

            SupportCaseInfolistSection::make(),

            Section::make('التوقف أو الإلغاء')
                ->schema([
                    TextEntry::make('stop_reason')->label('سبب التوقف')->placeholder('—'),
                    TextEntry::make('stopped_at')->label('وقت التوقف')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('cancel_reason')->label('سبب الإلغاء')->placeholder('—'),
                    TextEntry::make('cancelled_at')->label('وقت الإلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                ])
                ->columns(2)
                ->visible(fn (DeliveryOrder $record): bool => filled($record->stop_reason) || filled($record->cancel_reason)),
        ]);
    }

    private static function sourceUrl(DeliveryOrder $record): ?string
    {
        return match (true) {
            $record->source instanceof Order => OrderResource::getUrl('view', ['record' => $record->source]),
            $record->source instanceof SmOrder => SmOrderResource::getUrl('view', ['record' => $record->source]),
            default => null,
        };
    }
}
