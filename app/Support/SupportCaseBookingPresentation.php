<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SupportCase;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

final class SupportCaseBookingPresentation
{
    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return [
            'cleaning_booking' => 'التنظيف',
            'restaurant_order' => 'المطاعم',
            'supermarket_order' => 'السوبرماركت',
            'delivery_order' => 'التوصيل',
        ];
    }

    /** @return array<class-string, array<int, string>> */
    public static function morphRelations(): array
    {
        return [
            CleaningBooking::class => ['customer', 'worker.user'],
            Order::class => ['customer', 'restaurant'],
            SmOrder::class => ['customer', 'store'],
            DeliveryOrder::class => ['createdBy', 'driver.user', 'company'],
        ];
    }

    public static function typeLabel(SupportCase $record): string
    {
        $type = self::normalizeType((string) $record->booking_type);

        return self::typeOptions()[$type] ?? $type;
    }

    public static function normalizeType(string $type): string
    {
        return match ($type) {
            CleaningBooking::class => 'cleaning_booking',
            Order::class => 'restaurant_order',
            SmOrder::class => 'supermarket_order',
            DeliveryOrder::class => 'delivery_order',
            default => $type,
        };
    }

    public static function storedType(string $type): string
    {
        return match ($type) {
            'restaurant_order' => Order::class,
            'supermarket_order' => SmOrder::class,
            default => $type,
        };
    }

    public static function reference(SupportCase $record): string
    {
        $booking = $record->booking;

        return match (true) {
            $booking instanceof CleaningBooking => (string) ($booking->booking_number ?: $booking->id),
            $booking instanceof Order,
            $booking instanceof SmOrder,
            $booking instanceof DeliveryOrder => (string) ($booking->order_number ?: $booking->id),
            default => (string) $record->booking_id,
        };
    }

    public static function status(SupportCase $record): string
    {
        $status = $record->booking?->getAttribute('status');

        return $status instanceof BackedEnum ? (string) $status->value : (string) ($status ?? '-');
    }

    public static function customerName(SupportCase $record): ?string
    {
        $booking = $record->booking;

        return match (true) {
            $booking instanceof CleaningBooking => $booking->customer?->name,
            $booking instanceof Order => $booking->customer?->name,
            $booking instanceof SmOrder => $booking->customer?->name,
            $booking instanceof DeliveryOrder => $booking->createdBy?->name ?: $booking->customer_name,
            default => null,
        };
    }

    public static function customerPhone(SupportCase $record): ?string
    {
        $booking = $record->booking;

        return match (true) {
            $booking instanceof CleaningBooking => $booking->customer?->phone,
            $booking instanceof Order => $booking->customer?->phone,
            $booking instanceof SmOrder => $booking->customer?->phone,
            $booking instanceof DeliveryOrder => $booking->createdBy?->phone ?: $booking->customer_phone,
            default => null,
        };
    }

    public static function counterpartLabel(SupportCase $record): string
    {
        return match (true) {
            $record->booking instanceof CleaningBooking => 'العامل',
            $record->booking instanceof Order => 'المطعم',
            $record->booking instanceof SmOrder => 'المتجر',
            $record->booking instanceof DeliveryOrder => 'المندوب',
            default => 'الطرف المرتبط',
        };
    }

    public static function counterpartName(SupportCase $record): ?string
    {
        $booking = $record->booking;

        return match (true) {
            $booking instanceof CleaningBooking => $booking->worker?->user?->name ?: $booking->worker?->first_name,
            $booking instanceof Order => $booking->restaurant?->name,
            $booking instanceof SmOrder => $booking->store?->name,
            $booking instanceof DeliveryOrder => $booking->driver?->user?->name ?: $booking->driver?->first_name,
            default => null,
        };
    }

    public static function counterpartPhone(SupportCase $record): ?string
    {
        $booking = $record->booking;

        return match (true) {
            $booking instanceof CleaningBooking => $booking->worker?->user?->phone,
            $booking instanceof Order => $booking->restaurant?->phone,
            $booking instanceof SmOrder => $booking->store?->phone,
            $booking instanceof DeliveryOrder => $booking->driver?->user?->phone ?: $booking->driver?->phone,
            default => null,
        };
    }

    public static function applyReferenceSearch(Builder $query, string $search): Builder
    {
        return $query->whereHasMorph(
            'booking',
            [CleaningBooking::class, Order::class, SmOrder::class, DeliveryOrder::class],
            function (Builder $bookingQuery, string $type) use ($search): void {
                $column = $type === CleaningBooking::class ? 'booking_number' : 'order_number';
                $bookingQuery->where($column, 'like', "%{$search}%");
            },
        );
    }
}
