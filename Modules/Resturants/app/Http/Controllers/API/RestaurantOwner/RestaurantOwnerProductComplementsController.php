<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API\RestaurantOwner;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Support\RestaurantOwnerContext;
use Modules\User\Services\RestaurantMealComplements;

final class RestaurantOwnerProductComplementsController
{
    public function __construct(private RestaurantOwnerContext $ownerContext) {}

    public function show(Product $product): JsonResponse
    {
        $this->ownerContext->ensureOwnedProduct($product);

        return $this->payload($product);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ownerContext->ensureOwnedProduct($product);
        $data = $request->validate([
            'mode' => ['required', 'in:automatic,custom,disabled'],
            'productIds' => ['present', 'array', 'list', 'max:6'],
            'productIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);
        $ids = array_map('intval', array_values($data['productIds']));
        if (($data['mode'] === 'custom' && $ids === []) ||
            ($data['mode'] !== 'custom' && $ids !== [])) {
            throw ValidationException::withMessages(['productIds' => ['اختر من منتج واحد إلى ستة في الوضع المخصص فقط.']]);
        }

        return DB::transaction(function () use ($product, $data, $ids): JsonResponse {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->ownerContext->ensureOwnedProduct($locked);
            if (! RestaurantMealComplements::supports($locked)) {
                throw ValidationException::withMessages(['product' => ['المنتجات المرافقة مخصصة للوجبات الرئيسية والكومبو.']]);
            }
            if ($ids !== [] && RestaurantMealComplements::ownerCandidates($locked)->whereIn('products.id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['productIds' => ['اختر منتجات جانبية أو مشروبات أو حلويات أو صوصات مستقلة من مطعمك فقط.']]);
            }

            $locked->complementary_product_ids = $data['mode'] === 'automatic' ? null : $ids;
            $locked->save();

            return $this->payload($locked);
        });
    }

    private function payload(Product $product): JsonResponse
    {
        $supported = RestaurantMealComplements::supports($product);
        $ids = $product->complementary_product_ids;
        $candidates = $supported
            ? RestaurantMealComplements::ownerCandidates($product)
                ->with(['media', 'offers'])->orderBy('name')->orderBy('id')->get()
                ->map(fn (Product $item): array => [
                    'id' => (int) $item->id,
                    'name' => $item->name,
                    'productKind' => $item->product_kind,
                    'price' => number_format($item->effectivePrice(), 2, '.', ''),
                    'currency' => config('app.currency', 'SYP'),
                    'imageUrl' => $item->getFirstMediaUrl('primary-image') ?: null,
                    'isAvailable' => $item->isAvailableNow() && ($item->stock_quantity === null || $item->stock_quantity > 0),
                ])->values()->all()
            : [];

        return response()->json(['data' => [
            'supported' => $supported,
            'mode' => $ids === null ? 'automatic' : ($ids === [] ? 'disabled' : 'custom'),
            'productIds' => $ids ?? [],
            'candidates' => $candidates,
        ]])->header('Cache-Control', 'no-store, private');
    }
}
