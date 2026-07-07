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
        $existing = $this->existingFor(self::SOURCE_RESTAURANT_ORDER, (int) $order->id);
        if ($existing instanceof DeliveryOrder) {
            return $existing;
        }

        $order->loadMissing(['user', 'restaurant', 'userAddress']);

        if (! $order->restaurant instanceof Restaurant) {
            throw ValidationException::withMessages(['restaurant' => ['Cannot create delivery order without a linked restaurant.']]);
        }
        if (! $order->userAddress instanceof UserAddress) {
            throw ValidationException::withMessages(['addressId' => ['Please choose a valid delivery address.']]);
        }

        return $this->deliveryOrders->create(
            company: $this->resolveCompany(),
            payload: [
                'customerName' => (string) ($order->user?->name ?? 'Dllni Customer'),
                'customerPhone' => $order->userAddress->mobile ?? $order->user?->phone,
                'customerNotes' => $order->special_instructions,
                'pickupAddress' => $this->merchantAddress(address: $order->restaurant->address, city: $order->restaurant->city, area: $order->restaurant->district, fallback: $order->restaurant->name),
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

    public function createForSupermarketOrder(SmOrder $order, UserAddress $address): DeliveryOrder
    {
        $existing = $this->existingFor(self::SOURCE_SUPERMARKET_ORDER, (int) $order->id);
        if ($existing instanceof DeliveryOrder) {
            return $existing;
        }

        $order->loadMissing(['customer', 'store']);

        if (! $order->store instanceof SmStore) {
            throw ValidationException::withMessages(['store' => ['Cannot create delivery order without a linked supermarket store.']]);
        }

        return $this->deliveryOrders->create(
            company: $this->resolveCompany(),
            payload: [
                'customerName' => (string) ($order->customer?->name ?? 'Dllni Customer'),
                'customerPhone' => $address->mobile ?? $order->customer?->phone,
                'customerNotes' => $order->special_instructions,
                'pickupAddress' => $this->merchantAddress(address: $order->store->address, city: $order->store->city, area: $order->store->neighborhood, fallback: $order->store->name),
                'pickupLatitude' => $this->requiredCoordinate($order->store->latitude, 'pickupLatitude'),
                'pickupLongitude' => $this->requiredCoordinate($order->store->longitude, 'pickupLongitude'),
                'dropoffAddress' => $this->userAddressText($address),
                'dropoffLatitude' => $this->requiredCoordinate($address->latitude, 'dropoffLatitude'),
                'dropoffLongitude' => $this->requiredCoordinate($address->longitude, 'dropoffLongitude'),
                'currency' => 'SYP',
                'sourceType' => self::SOURCE_SUPERMARKET_ORDER,
                'sourceId' => (int) $order->id,
                'dispatchImmediately' => false,
            ],
            createdByUserId: (int) $order->customer_id,
        );
    }

    private function existingFor(string $sourceType, int $sourceId): ?DeliveryOrder
    {
        return DeliveryOrder::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function resolveCompany(): DeliveryCompany
    {
        $company = DeliveryCompany::query()->where('is_active', true)->where('is_suspended', false)->oldest('id')->first();

        if (! $company instanceof DeliveryCompany) {
            throw ValidationException::withMessages(['delivery' => ['No delivery company is available right now.']]);
        }

        return $company;
    }

    private function requiredCoordinate(mixed $value, string $field): float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw ValidationException::withMessages([
                'delivery' => ['Cannot create delivery order without pickup and dropoff coordinates.'],
                $field => ['Missing delivery coordinate.'],
            ]);
        }

        return (float) $value;
    }

    private function merchantAddress(?string $address, ?string $city, ?string $area, ?string $fallback): string
    {
        $parts = array_values(array_filter([$fallback, $city, $area, $address], fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        return implode(' - ', $parts) ?: 'Pickup point';
    }

    private function userAddressText(UserAddress $address): string
    {
        $parts = array_values(array_filter([$address->label, $address->city, $address->neighborhood, $address->street, $address->building, $address->floor, $address->directions], fn (?string $value): bool => is_string($value) && trim($value) !== ''));

        return implode(' - ', $parts) ?: 'Customer address';
    }
}
