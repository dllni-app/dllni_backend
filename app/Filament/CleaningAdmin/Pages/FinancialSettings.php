<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\CleaningFinancialSetting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

final class FinancialSettings extends Page
{
    public float $defaultCommissionRate = 0.0;

    public float $vatRate = 0.0;

    public string $commissionType = 'percent';

    public ?float $commissionFixedAmount = null;

    public string $travelMarkupType = 'fixed';

    public float $travelMarkupValue = 0.0;

    public string $travelDistanceStartPoint = 'auto';

    public int $coverageLow = 3;

    public int $coverageOk = 7;

    public string $timeBillingMode = 'actual';

    public ?int $minBillableMinutes = null;

    public ?int $timeWarningMinutesBeforeEnd = null;

    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedCurrencyDollar;

    protected string $view = 'filament.cleaning-admin.pages.financial-settings';

    protected static ?string $navigationLabel = 'الإعدادات المالية';

    protected static ?string $title = 'الإعدادات المالية';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 9;

    public static function getNavigationTooltip(): ?string
    {
        return 'إعدادات التسعير الأساسي، الإضافات، العمولة، تكاليف التنقل، نقطة بدء المسافة، سياسة فوترة الوقت والحد الأدنى للدقائق القابلة للفوترة.';
    }

    public function getSubheading(): ?string
    {
        return 'إدارة التسعير الأساسي، الإضافات، العمولة، تكاليف التنقل، نقطة بدء احتساب المسافة، سياسة فوترة الوقت والحد الأدنى للدقائق القابلة للفوترة.';
    }

    public function mount(): void
    {
        $setting = CleaningFinancialSetting::query()->first();

        if (! $setting) {
            return;
        }

        $this->defaultCommissionRate = (float) $setting->default_commission_rate;
        $this->vatRate = (float) $setting->vat_rate;
        $this->commissionType = (string) ($setting->commission_type ?? 'percent');
        $this->commissionFixedAmount = $setting->commission_fixed_amount !== null ? (float) $setting->commission_fixed_amount : null;
        $this->travelMarkupType = (string) $setting->travel_markup_type;
        $this->travelMarkupValue = (float) $setting->travel_markup_value;
        $this->travelDistanceStartPoint = (string) ($setting->travel_distance_start_point ?? 'auto');
        $this->coverageLow = (int) data_get($setting->coverage_thresholds, 'low', 3);
        $this->coverageOk = (int) data_get($setting->coverage_thresholds, 'ok', 7);
        $this->timeBillingMode = (string) ($setting->time_billing_mode ?? 'actual');
        $this->minBillableMinutes = $setting->min_billable_minutes !== null ? (int) $setting->min_billable_minutes : null;
        $this->timeWarningMinutesBeforeEnd = $setting->time_warning_minutes_before_end !== null ? (int) $setting->time_warning_minutes_before_end : null;
    }

    public function save(): void
    {
        CleaningFinancialSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'default_commission_rate' => $this->defaultCommissionRate,
                'vat_rate' => $this->vatRate,
                'commission_type' => $this->commissionType,
                'commission_fixed_amount' => $this->commissionFixedAmount,
                'travel_markup_type' => $this->travelMarkupType,
                'travel_markup_value' => $this->travelMarkupValue,
                'travel_distance_start_point' => $this->travelDistanceStartPoint,
                'coverage_thresholds' => [
                    'low' => $this->coverageLow,
                    'ok' => $this->coverageOk,
                ],
                'time_billing_mode' => $this->timeBillingMode,
                'min_billable_minutes' => $this->minBillableMinutes,
                'time_warning_minutes_before_end' => $this->timeWarningMinutesBeforeEnd,
            ],
        );

        Notification::make()
            ->title('تم حفظ الإعدادات المالية')
            ->success()
            ->send();
    }
}
