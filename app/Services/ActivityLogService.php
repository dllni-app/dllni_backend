<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Spatie\Activitylog\Facades\Activity;

final class ActivityLogService
{
    public function logProductCreated(Product $product, int $restaurantId): void
    {
        $this->log(
            $product,
            'products',
            "أضاف منتجاً جديداً ({$product->name})",
            ['restaurant_id' => $restaurantId]
        );
    }

    public function logProductUpdated(Product $product, int $restaurantId, array $oldAttributes): void
    {
        $this->log(
            $product,
            'products',
            "عدّل بيانات المنتج ({$product->name})",
            [
                'restaurant_id' => $restaurantId,
                'old' => $oldAttributes,
                'new' => $product->getAttributes(),
            ]
        );
    }

    public function logProductDeleted(string $productName, int $restaurantId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('products')
            ->withProperties(['restaurant_id' => $restaurantId])
            ->log("حذف المنتج ({$productName})");
    }

    public function logProductAvailabilityChanged(Product $product, int $restaurantId, string $mode): void
    {
        $modeText = match ($mode) {
            'available' => 'متاح',
            'sold_out_today' => 'مباع اليوم',
            'manual_unavailable' => 'غير متاح',
            default => $mode,
        };

        $this->log(
            $product,
            'products',
            "عدّل توفر المنتج ({$product->name}) إلى: {$modeText}",
            ['restaurant_id' => $restaurantId, 'mode' => $mode]
        );
    }

    public function logOfferCreated(Offer $offer, int $restaurantId): void
    {
        $this->log(
            $offer,
            'offers',
            "أضاف عرضاً جديداً ({$offer->name})",
            ['restaurant_id' => $restaurantId]
        );
    }

    public function logOfferUpdated(Offer $offer, int $restaurantId, array $oldAttributes): void
    {
        $this->log(
            $offer,
            'offers',
            "عدّل بيانات العرض ({$offer->name})",
            [
                'restaurant_id' => $restaurantId,
                'old' => $oldAttributes,
                'new' => $offer->getAttributes(),
            ]
        );
    }

    public function logOfferDeleted(string $offerName, int $restaurantId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('offers')
            ->withProperties(['restaurant_id' => $restaurantId])
            ->log("حذف العرض ({$offerName})");
    }

    public function logOrderAccepted(int $orderId, string $orderNumber, int $restaurantId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('orders')
            ->withProperties([
                'restaurant_id' => $restaurantId,
                'order_id' => $orderId,
            ])
            ->log("قبل الطلب رقم #{$orderNumber}");
    }

    public function logOrderRejected(int $orderId, string $orderNumber, int $restaurantId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('orders')
            ->withProperties([
                'restaurant_id' => $restaurantId,
                'order_id' => $orderId,
            ])
            ->log("رفض الطلب رقم #{$orderNumber}");
    }

    public function logSmProductCreated(SmProduct $product, int $storeId): void
    {
        $this->log(
            $product,
            'products',
            "أضاف منتجاً جديداً ({$product->name})",
            ['store_id' => $storeId]
        );
    }

    public function logSmProductUpdated(SmProduct $product, int $storeId, array $oldAttributes): void
    {
        $this->log(
            $product,
            'products',
            "عدّل بيانات المنتج ({$product->name})",
            [
                'store_id' => $storeId,
                'old' => $oldAttributes,
                'new' => $product->getAttributes(),
            ]
        );
    }

    public function logSmProductDeleted(?string $productName, int $storeId): void
    {
        $name = trim((string) $productName);

        Activity::causedBy(Auth::user())
            ->inLog('products')
            ->withProperties(['store_id' => $storeId])
            ->log('حذف المنتج' . ($name !== '' ? " ({$name})" : ''));
    }

    public function logSmOfferCreated(SmOffer $offer, int $storeId): void
    {
        $this->log(
            $offer,
            'offers',
            "أضاف عرضاً جديداً ({$offer->name})",
            ['store_id' => $storeId]
        );
    }

    public function logSmOfferUpdated(SmOffer $offer, int $storeId, array $oldAttributes): void
    {
        $this->log(
            $offer,
            'offers',
            "عدّل بيانات العرض ({$offer->name})",
            [
                'store_id' => $storeId,
                'old' => $oldAttributes,
                'new' => $offer->getAttributes(),
            ]
        );
    }

    public function logSmOfferDeleted(?string $offerName, int $storeId): void
    {
        $name = trim((string) $offerName);

        Activity::causedBy(Auth::user())
            ->inLog('offers')
            ->withProperties(['store_id' => $storeId])
            ->log('حذف العرض' . ($name !== '' ? " ({$name})" : ''));
    }

    public function logSmOrderAccepted(int $orderId, string $orderNumber, int $storeId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('orders')
            ->withProperties([
                'store_id' => $storeId,
                'order_id' => $orderId,
            ])
            ->log("قبل الطلب رقم #{$orderNumber}");
    }

    public function logSmOrderRejected(int $orderId, string $orderNumber, int $storeId): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('orders')
            ->withProperties([
                'store_id' => $storeId,
                'order_id' => $orderId,
            ])
            ->log("رفض الطلب رقم #{$orderNumber}");
    }

    public function logSmStockUpdated(SmProduct $product, int $storeId, int $quantityChange): void
    {
        $this->log(
            $product,
            'inventory',
            "عدّل مخزون المنتج ({$product->name}) بمقدار {$quantityChange}",
            ['store_id' => $storeId, 'quantity_change' => $quantityChange]
        );
    }

    public function logSmInventoryAudit(int $storeId, int $auditedItemsCount): void
    {
        Activity::causedBy(Auth::user())
            ->inLog('inventory')
            ->withProperties([
                'store_id' => $storeId,
                'audited_items_count' => $auditedItemsCount,
            ])
            ->log("أجرى جرداً للمخزون ({$auditedItemsCount} منتج)");
    }

    public function logSmExpirationUpdated(SmProduct $product, int $storeId): void
    {
        $this->log(
            $product,
            'inventory',
            "عدّل تاريخ انتهاء صلاحية المنتج ({$product->name})",
            ['store_id' => $storeId]
        );
    }

    private function log(Model $model, string $logName, string $description, array $properties): void
    {
        Activity::causedBy(Auth::user())
            ->performedOn($model)
            ->inLog($logName)
            ->withProperties($properties)
            ->log($description);
    }
}
