<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Services\ActivityLogService;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Data\ProductData;
use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Helpers\MediaHelper;

final class ProductService
{
    private const TYPE_PERCENTAGE = 'percentage';
    private const TYPE_FIXED_AMOUNT = 'fixed_amount';

    public function __construct(private ActivityLogService $activityLogService) {}

    public function store(ProductData $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create($this->prepareProductAttributes($data->onlyModelAttributes()));
            $this->attachMedia($data, $product, false);
            $this->activityLogService->logProductCreated($product, (int) $product->restaurant_id);

            return $product;
        });
    }

    public function update(ProductData $data, Product $product): Product
    {
        return DB::transaction(function () use ($data, $product) {
            $oldAttributes = $product->getAttributes();
            tap($product)->update($this->prepareProductAttributes($data->onlyModelAttributes()));
            $this->attachMedia($data, $product, true);
            $this->activityLogService->logProductUpdated($product, (int) $product->restaurant_id, $oldAttributes);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareProductAttributes(array $attributes): array
    {
        $discountType = $this->normalizeDiscountType($attributes['discount_type'] ?? null);
        $discountValue = $attributes['discount_value'] ?? null;

        if ($discountType === null || $discountValue === null || $discountValue === '') {
            return $this->clearDiscountFields($attributes);
        }

        $price = round(max((float) ($attributes['price'] ?? 0), 0), 2);
        $discountValue = round(max((float) $discountValue, 0), 2);

        if ($price <= 0 || $discountValue <= 0) {
            return $this->clearDiscountFields($attributes);
        }

        $discountAmount = match ($discountType) {
            self::TYPE_PERCENTAGE => round($price * ($discountValue / 100), 2),
            self::TYPE_FIXED_AMOUNT => $discountValue,
        };

        $discountAmount = min($discountAmount, max($price - 0.01, 0));

        $attributes['discount_type'] = $discountType;
        $attributes['discount_value'] = $discountValue;
        $attributes['discounted_price'] = round(max($price - $discountAmount, 0), 2);

        return $attributes;
    }

    private function normalizeDiscountType(mixed $discountType): ?string
    {
        return match ((string) $discountType) {
            'percent', self::TYPE_PERCENTAGE => self::TYPE_PERCENTAGE,
            'fixed', self::TYPE_FIXED_AMOUNT => self::TYPE_FIXED_AMOUNT,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clearDiscountFields(array $attributes): array
    {
        $attributes['discount_type'] = null;
        $attributes['discount_value'] = null;
        $attributes['discounted_price'] = null;

        return $attributes;
    }

    private function attachMedia(ProductData $data, Product $product, bool $isUpdate): void
    {
        if ($data->primaryImage !== null) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->primaryImage, $product, 'primary-image');
            } else {
                MediaHelper::uploadMedia($data->primaryImage, $product, 'primary-image');
            }
        }

        if ($data->images !== null && $data->images !== []) {
            if ($isUpdate) {
                MediaHelper::updateMedia($data->images, $product, 'images');
            } else {
                MediaHelper::uploadMedia($data->images, $product, 'images');
            }
        }
    }
}
