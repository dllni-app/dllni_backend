<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\RestaurantFinancialSetting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class RestaurantFinancialSettings extends Page
{
    public float $baseHourPrice = 0.0;

    public int $minHours = 1;

    public array $addonsPricing = [];

    public string $commissionType = 'percent';

    public float $commissionValue = 0.0;

    public float $travelPerKm = 0.0;

    public float $travelMinimum = 0.0;

    public string $distanceStartPoint = 'auto';

    public string $billingMode = 'actual';

    public ?int $minActualMinutes = null;

    public int $timeWarningMinutesBeforeEnd = 15;

    public int $coverageLow = 3;

    public int $coverageGood = 7;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $title = 'الإعدادات المالية للمطاعم';

    protected static ?string $navigationLabel = 'الإعدادات المالية';

    protected static string|UnitEnum|null $navigationGroup = 'قسم المطاعم';

    protected string $view = 'filament.cleaning-admin.pages.restaurant-financial-settings';

    public static function getNavigationTooltip(): ?string
    {
        return 'إدارة التسعير والعمولات وبدل الانتقال وسياسة المحاسبة لقسم المطاعم.';
    }

    public function mount(): void
    {
        $setting = RestaurantFinancialSetting::query()->first();

        if (! $setting) {
            return;
        }

        $this->baseHourPrice = (float) $setting->base_hour_price;
        $this->minHours = (int) $setting->min_hours;
        $this->addonsPricing = (array) ($setting->addons_pricing ?? []);
        $this->commissionType = (string) $setting->commission_type;
        $this->commissionValue = (float) $setting->commission_value;
        $this->travelPerKm = (float) $setting->travel_per_km;
        $this->travelMinimum = (float) $setting->travel_minimum;
        $this->distanceStartPoint = (string) $setting->distance_start_point;
        $this->billingMode = (string) $setting->billing_mode;
        $this->minActualMinutes = $setting->min_actual_minutes !== null ? (int) $setting->min_actual_minutes : null;
        $this->timeWarningMinutesBeforeEnd = (int) $setting->time_warning_minutes_before_end;
        $this->coverageLow = (int) data_get($setting->coverage_thresholds, 'low', 3);
        $this->coverageGood = (int) data_get($setting->coverage_thresholds, 'good', 7);
    }

    public function save(): void
    {
        RestaurantFinancialSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'base_hour_price' => $this->baseHourPrice,
                'min_hours' => $this->minHours,
                'addons_pricing' => $this->addonsPricing,
                'commission_type' => $this->commissionType,
                'commission_value' => $this->commissionValue,
                'travel_per_km' => $this->travelPerKm,
                'travel_minimum' => $this->travelMinimum,
                'distance_start_point' => $this->distanceStartPoint,
                'billing_mode' => $this->billingMode,
                'min_actual_minutes' => $this->minActualMinutes,
                'time_warning_minutes_before_end' => $this->timeWarningMinutesBeforeEnd,
                'coverage_thresholds' => [
                    'low' => $this->coverageLow,
                    'good' => $this->coverageGood,
                ],
            ],
        );

        Notification::make()->title(__('restaurant_admin.financial.saved'))->success()->send();
    }
}
