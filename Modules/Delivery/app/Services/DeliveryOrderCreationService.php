<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

final class DeliveryOrderCreationService
{
    public const SOURCE_RESTAURANT_ORDER = 'restaurant_order';
    public const SOURCE_SUPERMARKET_ORDER = 'supermarket_order';

    public function __construct(
        private readonly DeliveryOrderService $deliveryOrders,
    ) {}

    public function createForRestaurantOrder(Order $order): DeliveryOrder
    {
        $order->loadMissing(['user', 'restaurant', 'userAddress']);

        if (! $order->restaurant instanceof Restaurant) {
            throw ValidationException::withMessages([
                'restaurant' => ['لا يمكن إنشاء طلب توصيل بدون مطعم مرتبط بالطلب.'],
            ]);
        }

        if (! $order->userAddress instanceof UserAddress) {
            throw ValidationException::withMessages([
                'addressId' => ['يرجى اختيار عنوان توصيل صالح.'],
            ]);
        }

        return $this->deliveryOrders->create(
            company: $this->resolveCompany(),
            payload: [
                'customerName' => (string) ($order->user?->name ?? 'عميل دللني'),
                'customerPhone' => $order->userAddress->mobile ?? $order->user?->phone,
                'customerNotes' => $order->special_instructions,
                'pickupAddress' => $this->merchantAddress(
                    address: $order->restaurant->address,
                    city: $order->restaurant->city,
                    area: $order->restaurant->district,
                    fallback: $order->restaurant->name,
                ),
                'pickupLatitude' => $this->requiredCoordinate($order->restaurant->latitude, 'pickupLatitude'),
                'pickupLongitude' => $this->requiredCoordinate($order->restaurant->longitude, 'pickupLongitude'),
                'dropoffAddress' => $this->userAddressText($order->userAddress),
                'dropoffLatitude' => $this->requiredCoordinate($order->userAddress->latitude, 'dropoffLatitude'),
                'dropoffLongitude' => $this->requiredCoordinate($order->userAddress->longitude, 'dropoffLongitude'),
                'currency' => 'SYP',
                'sourceType' => self::SOURCE_RESTAURANT_ORDER,
                'sourceId' => (int) $order->id,
            ],
            createdByUserId: (int) $order->user_id,
        );
    }

    public function createForSupermarketOrder(SmOrder $order): DeliveryOrder
    {
        $order->loadMissing(['customer', 'store']);

        if (! $order->store instanceof SmStore) {
            throw ValidationException::withMessages([
                'store' => ['لا يمكن إنشاء طلب توصيل بدون متجر مرتبط بالطلب.'],
            ]);
        }

        $address = $order->getRelation('deliveryUserAddress') instanceof UserAddress
            ? $order->getRelation('deliveryUserAddress')
            : null;

        if (! $address instanceof UserAddress) {
            throw ValidationException::withMessages([
                'addressId' => ['يرجى اختيار عنوان توصيل صالح.'],
            ]);
        }

        return $this->deliveryOrders->create(
            company: $this->resolveCompany(),
            payload: [
                'customerName' => (string) ($order->customer?->name ?? 'عميل دللني'),
                'customerPhone' => $address->mobile ?? $order->customer?->phone,
                'customerNotes' => $order->special_instructions,
                'pickupAddress' => $this->merchantAddress(
                    address: $order->store->address,
                    city: $order->store->city,
                    area: $order->store->neighborhood,
                    fallback: $order->store->name,
                ),
                'pickupLatitude' => $this->requiredCoordinate($order->store->latitude, 'pickupLatitude'),
                'pickupLongitude' => $this->requiredCoordinate($order->store->longitude, 'pickupLongitude'),
                'dropoffAddress' => $this->userAddressText($address),
                'dropoffLatitude' => $this->requiredCoordinate($address->latitude, 'dropoffLatitude'),
                'dropoffLongitude' => $this->requiredCoordinate($address->longitude, 'dropoffLongitude'),
                'currency' => 'SYP',
                'sourceType' => self::SOURCE_SUPERMARKET_ORDER,
                'sourceId' => (int) $order->id,
            ],
            createdByUserId: (int) $order->customer_id,
        );
    }

    private function resolveCompany(): DeliveryCompany
    {
        $company = DeliveryCompany::query()
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->oldest('id')
            ->first();

        if (! $company instanceof DeliveryCompany) {
            throw ValidationException::withMessages([
                'delivery' => ['لا توجد شركة توصيل متاحة حالياً.'],
            ]);
        }

        return $company;
    }

    private function requiredCoordinate(mixed $value, string $field): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'delivery' => ['لا يمكن إنشاء طلب توصيل بدون تحديد موقع الاستلام والتسليم.'],
                $field => ['Missing delivery coordinate.'],
            ]);
        }

        return (float) $value;
    }

    private function merchantAddress(?string $address, ?string $city, ?string $area, ?string $fallback): string
    {
        $parts = array_values(array_filter([
            $fallback,
            $city,
            $area,
            $address,
        ], fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        return implode(' - ', $parts) ?: 'نقطة الاستلام';
    }

    private function userAddressText(UserAddress $address): string
    {
        $parts = array_values(array_filter([
            $address->label,
            $address->city,
            $address->neighborhood,
            $address->street,
            $address->building,
            $address->floor,
            $address->directions,
        ], fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        return implode(' - ', $parts) ?: 'عنوان العميل';
    }
}
