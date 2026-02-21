<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Supermarket\Data\SmCouponData;
use Modules\Supermarket\Http\Requests\SmCouponRequest;
use Modules\Supermarket\Http\Requests\SmCouponRequests\SmCouponFilterRequest;
use Modules\Supermarket\Http\Resources\SmCouponResource;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Services\SmCouponService;

final class SmCouponController
{
    public function __construct(
        private SmCouponService $service
    ) {}

    public function index(SmCouponFilterRequest $request): AnonymousResourceCollection
    {
        $coupons = SmCoupon::getQuery()->paginate($request->get('perPage', 20));

        return SmCouponResource::collection($coupons);
    }

    public function store(SmCouponRequest $request): SmCouponResource
    {
        $coupon = $this->service->store(SmCouponData::from($request->validated()));

        return SmCouponResource::make($coupon->load('store'));
    }

    public function show(SmCoupon $smCoupon): SmCouponResource
    {
        return SmCouponResource::make($smCoupon->load('store'));
    }

    public function update(SmCouponRequest $request, SmCoupon $smCoupon): SmCouponResource
    {
        $coupon = $this->service->update(SmCouponData::from($request->validated()), $smCoupon);

        return SmCouponResource::make($coupon->load('store'));
    }

    public function destroy(SmCoupon $smCoupon): Response
    {
        $smCoupon->delete();

        return response()->noContent();
    }
}
