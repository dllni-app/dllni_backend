<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Pages;

use App\Models\CleaningFinancialSetting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

final class FinancialSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedCurrencyDollar;

    protected string $view = 'filament.cleaning-admin.pages.financial-settings';

    protected static ?string $navigationLabel = 'الإعدادات المالية';

    protected static ?string $title = 'الإعدادات المالية';

    protected static ?string $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 1;

    public float $defaultCommissionRate = 0.0;

    public float $vatRate = 0.0;

    public string $travelMarkupType = 'fixed';

    public float $travelMarkupValue = 0.0;

    public int $coverageLow = 3;

    public int $coverageOk = 7;

    public function mount(): void
    {
        $setting = CleaningFinancialSetting::query()->first();

        if (! $setting) {
            return;
        }

        $this->defaultCommissionRate = (float) $setting->default_commission_rate;
        $this->vatRate = (float) $setting->vat_rate;
        $this->travelMarkupType = (string) $setting->travel_markup_type;
        $this->travelMarkupValue = (float) $setting->travel_markup_value;
        $this->coverageLow = (int) data_get($setting->coverage_thresholds, 'low', 3);
        $this->coverageOk = (int) data_get($setting->coverage_thresholds, 'ok', 7);
    }

    public function save(): void
    {
        CleaningFinancialSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'default_commission_rate' => $this->defaultCommissionRate,
                'vat_rate' => $this->vatRate,
                'travel_markup_type' => $this->travelMarkupType,
                'travel_markup_value' => $this->travelMarkupValue,
                'coverage_thresholds' => [
                    'low' => $this->coverageLow,
                    'ok' => $this->coverageOk,
                ],
            ],
        );

        Notification::make()
            ->title('تم حفظ الإعدادات المالية')
            ->success()
            ->send();
    }
}
