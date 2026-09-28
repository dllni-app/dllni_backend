<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Support\RestaurantOwnerContext;

final class RestaurantAnalyticsController
{
    public function dailyStats(Request $request, RestaurantOwnerContext $context): JsonResponse
    {
        [$restaurantId, $dateFrom, $dateTo] = $this->validatedRange($request, $context);

        $stats = DB::table('orders')
            ->where('restaurant_id', $restaurantId)
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('DATE(created_at) as stat_date')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->selectRaw('COALESCE(AVG(total_amount), 0) as average_order_value')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('stat_date')
            ->get()
            ->map(fn ($stat): array => [
                'statDate' => (string) $stat->stat_date,
                'ordersCount' => (int) $stat->orders_count,
                'revenue' => round((float) $stat->revenue, 2),
                'averageOrderValue' => round((float) $stat->average_order_value, 2),
            ]);

        return response()->json(['data' => $stats]);
    }

    public function monthlyStats(Request $request, RestaurantOwnerContext $context): JsonResponse
    {
        [$restaurantId, $dateFrom, $dateTo] = $this->validatedRange($request, $context);

        $daily = DB::table('orders')
            ->where('restaurant_id', $restaurantId)
            ->where('status', OrderStatus::Completed->value)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('DATE(created_at) as stat_date')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as revenue')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('stat_date')
            ->get();

        $stats = $daily
            ->groupBy(fn ($row): string => mb_substr((string) $row->stat_date, 0, 7))
            ->map(function ($rows, string $yearMonth): array {
                [$year, $month] = array_map('intval', explode('-', $yearMonth));
                $ordersCount = (int) $rows->sum(fn ($row): int => (int) $row->orders_count);
                $revenue = (float) $rows->sum(fn ($row): float => (float) $row->revenue);

                return [
                    'statYear' => $year,
                    'statMonth' => $month,
                    'ordersCount' => $ordersCount,
                    'revenue' => round($revenue, 2),
                    'averageOrderValue' => $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0,
                ];
            })
            ->values();

        return response()->json(['data' => $stats]);
    }

    /** @return array{0:int,1:Carbon,2:Carbon} */
    private function validatedRange(Request $request, RestaurantOwnerContext $context): array
    {
        $isRestaurantPrefix = str_contains($request->path(), 'api/v1/restaurant/')
            && ! str_contains($request->path(), 'restaurant-owner');

        $request->validate([
            'restaurantId' => $isRestaurantPrefix ? ['required', 'exists:restaurants,id'] : ['prohibited'],
            'dateFrom' => ['required', 'date'],
            'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'],
        ]);

        $restaurantId = $context->restaurantId();

        if ($request->filled('restaurantId')) {
            abort_if((int) $request->input('restaurantId') !== $restaurantId, 404);
        }

        return [
            $restaurantId,
            Carbon::parse((string) $request->input('dateFrom'))->startOfDay(),
            Carbon::parse((string) $request->input('dateTo'))->endOfDay(),
        ];
    }
}
