<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\OperatingHour;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Services\RestaurantPickupWindowPolicy;
use Tests\TestCase;

final class RestaurantPickupWindowPolicyTest extends TestCase
{
    public function test_previous_day_overnight_window_is_clipped_to_requested_date(): void
    {
        $date = CarbonImmutable::now('Asia/Damascus')->addDays(2)->startOfDay();
        $merchant = $this->merchant([
            ['day_of_week' => mb_strtolower($date->subDay()->englishDayOfWeek), 'open_time' => '22:00:00', 'close_time' => '02:00:00', 'is_closed' => false],
        ]);
        $result = app(RestaurantPickupWindowPolicy::class)->forDate($merchant, $date->format('Y-m-d'));

        self::assertSame('available', $result['status']);
        self::assertCount(1, $result['windows']);
        self::assertSame('00:00', CarbonImmutable::parse($result['windows'][0]['startsAt'])->setTimezone('Asia/Damascus')->format('H:i'));
        self::assertSame('02:00', CarbonImmutable::parse($result['windows'][0]['endsAt'])->setTimezone('Asia/Damascus')->format('H:i'));
        app(RestaurantPickupWindowPolicy::class)->assertScheduled($merchant, $date->addHour()->toIso8601String());
    }

    public function test_closed_and_unknown_hours_are_distinct(): void
    {
        $date = CarbonImmutable::now('Asia/Damascus')->addDay()->format('Y-m-d');
        $policy = app(RestaurantPickupWindowPolicy::class);
        self::assertSame('unknown', $policy->forDate($this->merchant([]), $date)['status']);
        $closed = $this->merchant([]);
        $closed->is_temporarily_closed = true;
        self::assertSame('closed', $policy->forDate($closed, $date)['status']);
    }

    public function test_window_end_is_exclusive(): void
    {
        $date = CarbonImmutable::now('Asia/Damascus')->addDay()->startOfDay();
        $merchant = $this->merchant([
            ['day_of_week' => mb_strtolower($date->englishDayOfWeek), 'open_time' => '10:00:00', 'close_time' => '11:00:00', 'is_closed' => false],
        ]);
        $this->expectException(ValidationException::class);
        app(RestaurantPickupWindowPolicy::class)->assertScheduled($merchant, $date->setTime(11, 0)->toIso8601String());
    }

    private function merchant(array $hours): Restaurant
    {
        $merchant = new Restaurant(['is_active' => true, 'is_temporarily_closed' => false]);
        $merchant->id = 99;
        $merchant->setRelation('operatingHours', collect($hours)->map(fn (array $attributes): OperatingHour => new OperatingHour($attributes)));

        return $merchant;
    }
}
