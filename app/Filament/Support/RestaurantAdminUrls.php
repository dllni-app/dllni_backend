<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\RestaurantAdminReadinessFilter;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\RestaurantDisputes\RestaurantOrderDisputeResource;
use App\Filament\Resources\Restaurants\RestaurantResource;
use Modules\Resturants\Enums\OrderStatus;

final class RestaurantAdminUrls
{
    public static function restaurantsIndex(?RestaurantAdminReadinessFilter $readiness = null): string
    {
        $url = RestaurantResource::getUrl('index');
        if ($readiness === null) {
            return $url;
        }

        return $url.'?'.http_build_query([
            'filters' => [
                'readiness' => ['value' => $readiness->value],
            ],
        ]);
    }

    public static function restaurantsTemporarilyClosed(): string
    {
        return RestaurantResource::getUrl('index').'?'.http_build_query([
            'filters' => [
                'temp_closure' => ['value' => '1'],
            ],
        ]);
    }

    public static function ordersPending(): string
    {
        return OrderResource::getUrl('index').'?'.http_build_query([
            'filters' => [
                'status' => ['value' => OrderStatus::Pending->value],
            ],
        ]);
    }

    public static function disputesOpenOrReview(): string
    {
        return RestaurantOrderDisputeResource::getUrl('index').'?'.http_build_query([
            'filters' => [
                'open_status' => ['value' => 'open_or_review'],
            ],
        ]);
    }
}
