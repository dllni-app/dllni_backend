<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\RestaurantPickupWindowPolicy;

final class RestaurantV2PickupWindowsController
{
    public function __invoke(Request $request, int $restaurant, RestaurantPickupWindowPolicy $policy): JsonResponse
    {
        Validator::make($request->query(), ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $date = (string) $request->query('date');
        if ($date < CarbonImmutable::now($policy->timezone())->format('Y-m-d')) {
            throw ValidationException::withMessages(['date' => 'Date must be today or later.']);
        }
        $merchant = Restaurant::query()->where('is_active', true)->with('operatingHours')->findOrFail($restaurant);

        return response()->json(['data' => $policy->forDate($merchant, $date)]);
    }
}
