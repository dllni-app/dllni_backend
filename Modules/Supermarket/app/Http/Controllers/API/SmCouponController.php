<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Supermarket\Data\SmCouponData;
use Modules\Supermarket\Http\Requests\SmCouponRequest;
use Modules\Supermarket\Http\Requests\SmCouponRequests\SmCouponFilterRequest;
use Modules\Supermarket\Http\Requests\SmCouponRequests\SmCouponWeeklyAnalysisRequest;
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

    public function weeklyAnalysis(SmCouponWeeklyAnalysisRequest $request): JsonResponse
    {
        $storeId = $request->integer('storeId');

        $endDate = Carbon::now()->endOfDay();
        $startDate = Carbon::now()->subDays(6)->startOfDay();

        $couponsPerDay = SmCoupon::query()
            ->selectRaw('DATE(created_at) as date, is_active, COUNT(*) as total')
            ->when($storeId > 0, static function ($query) use ($storeId) {
                $query->where('store_id', $storeId);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('date', 'is_active')
            ->get();

        $indexedCounts = $couponsPerDay
            ->mapWithKeys(static function ($row): array {
                return [
                    sprintf('%s-%d', $row->date, (int) $row->is_active) => (int) $row->total,
                ];
            });

        $days = collect(range(0, 6))
            ->map(static function (int $offset) use ($startDate, $indexedCounts): array {
                $date = $startDate->copy()->addDays($offset)->toDateString();

                return [
                    'date' => $date,
                    'day' => Carbon::parse($date)->shortEnglishDayOfWeek,
                    'activeCoupons' => $indexedCounts->get("{$date}-1", 0),
                    'inactiveCoupons' => $indexedCounts->get("{$date}-0", 0),
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Weekly coupon analysis retrieved successfully.',
            'data' => [
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
                'days' => $days,
            ],
        ]);
    }
}
