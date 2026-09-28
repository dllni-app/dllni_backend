<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Schemas;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\EventBookings\EventBookingResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\SystemAlert;
use App\Support\BookingMorphTypeLabel;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

final class SystemAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('التنبيه')
                ->schema([
                    TextEntry::make('alert_type')->label('النوع')->badge()->formatStateUsing(fn ($s) => $s?->label() ?? '—'),
                    TextEntry::make('severity')->label('الخطورة')->badge()->formatStateUsing(fn ($s) => $s?->label() ?? '—'),
                    TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($s) => $s?->label() ?? '—'),
                    TextEntry::make('booking_type')->label('القسم')->formatStateUsing(fn (?string $s): string => BookingMorphTypeLabel::resolve($s)),
                    TextEntry::make('reference')->label('المرجع')->state(fn (SystemAlert $r): string => self::reference($r))->url(fn (SystemAlert $r): ?string => self::url($r)),
                    TextEntry::make('party')->label('العميل/الطرف')->state(fn (SystemAlert $r): string => self::party($r)),
                    TextEntry::make('payload.message')->label('الرسالة')->placeholder('—')->columnSpanFull(),
                ])->columns(3),
            Section::make('المتابعة الإدارية')
                ->schema([
                    TextEntry::make('acknowledgedBy.name')->label('تم الاستلام بواسطة')->placeholder('—'),
                    TextEntry::make('acknowledged_at')->label('وقت الاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('resolvedBy.name')->label('تم الحل بواسطة')->placeholder('—'),
                    TextEntry::make('resolved_at')->label('وقت الحل')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('resolution_note')->label('ملاحظة الحل')->placeholder('—')->columnSpanFull(),
                ])->columns(2),
            Section::make('بيانات إضافية')
                ->schema([
                    TextEntry::make('payload_json')
                        ->label('')
                        ->state(fn (SystemAlert $r): string => json_encode($r->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}')
                        ->columnSpanFull(),
                ])->collapsible()->collapsed(),
        ]);
    }

    private static function reference(SystemAlert $r): string
    {
        $b=$r->booking;
        return (string)($b?->order_number ?? $b?->booking_number ?? ('#'.($r->booking_id ?? '—')));
    }

    private static function party(SystemAlert $r): string
    {
        $b=$r->booking;
        return (string)($b?->customer?->name ?? $b?->user?->name ?? $b?->restaurant?->name ?? $b?->store?->name ?? $b?->company?->name ?? '—');
    }

    private static function url(SystemAlert $r): ?string
    {
        return match (true) {
            $r->booking instanceof Order => OrderResource::getUrl('view', ['record'=>$r->booking]),
            $r->booking instanceof SmOrder => SmOrderResource::getUrl('view', ['record'=>$r->booking]),
            $r->booking instanceof DeliveryOrder => DeliveryOrderResource::getUrl('view', ['record'=>$r->booking]),
            $r->booking instanceof CleaningBooking => CleaningBookingResource::getUrl('view', ['record'=>$r->booking]),
            $r->booking instanceof EventBooking => EventBookingResource::getUrl('view', ['record'=>$r->booking]),
            default => null,
        };
    }
}
