<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Resturants\Models\RestaurantDailyStat;
use Modules\Resturants\Models\RestaurantMonthlyStat;

final class RestaurantAnalyticsController
{
    public function dailyStats(Request $request): JsonResponse
    {
        $request->validate([
            'restaurantId' => 'required|exists:restaurants,id',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom',
        ]);

        $stats = RestaurantDailyStat::query()
            ->where('restaurant_id', $request->input('restaurantId'))
            ->whereBetween('stat_date', [$request->input('dateFrom'), $request->input('dateTo')])
            ->orderBy('stat_date')
            ->get()
            ->map(fn ($stat) => [
                'statDate' => $stat->stat_date->format('Y-m-d'),
                'ordersCount' => $stat->orders_count,
                'revenue' => (float) $stat->revenue,
                'averageOrderValue' => (float) $stat->average_order_value,
            ]);

        return response()->json(['data' => $stats]);
    }

    public function monthlyStats(Request $request): JsonResponse
    {
        $request->validate([
            'restaurantId' => 'required|exists:restaurants,id',
            'dateFrom' => 'required|date',
            'dateTo' => 'required|date|after_or_equal:dateFrom',
        ]);

        $from = $request->date('dateFrom');
        $to = $request->date('dateTo');

        $stats = RestaurantMonthlyStat::query()
            ->where('restaurant_id', $request->input('restaurantId'))
            ->where(function ($q) use ($from) {
                $q->where('stat_year', '>', $from->year)
                    ->orWhere(function ($q2) use ($from) {
                        $q2->where('stat_year', $from->year)
                            ->where('stat_month', '>=', $from->month);
                    });
            })
            ->where(function ($q) use ($to) {
                $q->where('stat_year', '<', $to->year)
                    ->orWhere(function ($q2) use ($to) {
                        $q2->where('stat_year', $to->year)
                            ->where('stat_month', '<=', $to->month);
                    });
            })
            ->orderBy('stat_year')
            ->orderBy('stat_month')
            ->get()
            ->map(fn ($stat) => [
                'statYear' => $stat->stat_year,
                'statMonth' => $stat->stat_month,
                'ordersCount' => $stat->orders_count,
                'revenue' => (float) $stat->revenue,
                'averageOrderValue' => (float) $stat->average_order_value,
            ]);

        return response()->json(['data' => $stats]);
    }
}
