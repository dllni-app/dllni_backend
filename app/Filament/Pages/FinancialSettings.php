<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Cleaning\Services\DepositService;

final class FinancialSettings extends Page
{
    public float $defaultCommissionRate = 0.0;

    public float $vatRate = 0.0;

    public string $commissionType = 'percent';

    public ?float $commissionFixedAmount = null;

    public string $travelMarkupType = 'fixed';

    public float $travelMarkupValue = 0.0;

    public float $travelPerKm = 0.0;

    public string $travelDistanceStartPoint = 'worker_home';

    public int $coverageLow = 3;

    public int $coverageOk = 7;

    public string $timeBillingMode = 'actual';

    public ?int $minBillableMinutes = null;

    public ?int $timeWarningMinutesBeforeEnd = null;

    public float $extensionRatePer30Minutes = 0.0;

    public float $minimumDepositAmount = 0.0;

    public float $defaultMaxNegativeBalance = 0.0;

    public int $trustRejectAfterAcceptPenalty = 10;

    public int $trustMinimumForDispatch = 0;

    public bool $workerFinanceEnabled = true;

    protected static string|BackedEnum|null $navigationIcon = \Filament\Support\Icons\Heroicon::OutlinedCurrencyDollar;

    protected string $view = 'filament.cleaning-admin.pages.financial-settings';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.financial.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.financial.tooltip');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can('pricing.view') || $user->can('settings.view');
    }

    public function getTitle(): string
    {
        return __('cleaning_admin.financial.title');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.financial.subheading');
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
        $this->travelPerKm = (float) ($setting->travel_per_km ?? 0.0);
        $this->travelDistanceStartPoint = 'worker_home';
        $this->coverageLow = (int) data_get($setting->coverage_thresholds, 'low', 3);
        $this->coverageOk = (int) data_get($setting->coverage_thresholds, 'ok', 7);
        $this->timeBillingMode = (string) ($setting->time_billing_mode ?? 'actual');
        $this->minBillableMinutes = $setting->min_billable_minutes !== null ? (int) $setting->min_billable_minutes : null;
        $this->timeWarningMinutesBeforeEnd = $setting->time_warning_minutes_before_end !== null ? (int) $setting->time_warning_minutes_before_end : null;
        $this->extensionRatePer30Minutes = (float) ($setting->extension_rate_per_30_minutes ?? 0.0);

        $depositSetting = CleaningDepositSetting::query()->first();
        if ($depositSetting) {
            $this->minimumDepositAmount = (float) $depositSetting->minimum_deposit_amount;
            $this->defaultMaxNegativeBalance = (float) $depositSetting->default_max_negative_balance;
            $this->trustRejectAfterAcceptPenalty = (int) $depositSetting->trust_reject_after_accept_penalty;
            $this->trustMinimumForDispatch = (int) $depositSetting->trust_minimum_for_dispatch;
            $this->workerFinanceEnabled = (bool) $depositSetting->is_enabled;
        }
    }

    public function save(): void
    {
        $this->validate([
            'defaultCommissionRate' => ['required', 'numeric', 'min:0'],
            'vatRate' => ['required', 'numeric', 'min:0'],
            'commissionType' => ['required', 'in:percent,fixed'],
            'commissionFixedAmount' => ['nullable', 'numeric', 'min:0', 'required_if:commissionType,fixed'],
            'travelMarkupType' => ['required', 'in:fixed,percent'],
            'travelMarkupValue' => ['required', 'numeric', 'min:0'],
            'travelPerKm' => ['required', 'numeric', 'min:0'],
            'coverageLow' => ['required', 'integer', 'min:0'],
            'coverageOk' => ['required', 'integer', 'gte:coverageLow'],
            'timeBillingMode' => ['required', 'in:full_booked,actual'],
            'minBillableMinutes' => ['nullable', 'integer', 'min:0'],
            'timeWarningMinutesBeforeEnd' => ['nullable', 'integer', 'min:0'],
            'extensionRatePer30Minutes' => ['required', 'numeric', 'min:0'],
            'minimumDepositAmount' => ['required', 'numeric', 'min:0'],
            'defaultMaxNegativeBalance' => ['required', 'numeric', 'min:0'],
            'trustRejectAfterAcceptPenalty' => ['required', 'integer', 'min:0'],
            'trustMinimumForDispatch' => ['required', 'integer', 'min:0', 'max:100'],
            'workerFinanceEnabled' => ['required', 'boolean'],
        ]);

        CleaningFinancialSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'default_commission_rate' => $this->defaultCommissionRate,
                'vat_rate' => $this->vatRate,
                'commission_type' => $this->commissionType,
                'commission_fixed_amount' => $this->commissionType === 'fixed' ? $this->commissionFixedAmount : null,
                'travel_markup_type' => $this->travelMarkupType,
                'travel_markup_value' => $this->travelMarkupValue,
                'travel_per_km' => $this->travelPerKm,
                'travel_distance_start_point' => 'worker_home',
                'coverage_thresholds' => [
                    'low' => $this->coverageLow,
                    'ok' => $this->coverageOk,
                ],
                'time_billing_mode' => $this->timeBillingMode,
                'min_billable_minutes' => $this->minBillableMinutes,
                'time_warning_minutes_before_end' => $this->timeWarningMinutesBeforeEnd,
                'extension_rate_per_30_minutes' => $this->extensionRatePer30Minutes,
            ],
        );

        CleaningDepositSetting::query()->updateOrCreate(
            ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
            [
                'minimum_deposit_amount' => $this->minimumDepositAmount,
                'default_max_negative_balance' => $this->defaultMaxNegativeBalance,
                'trust_reject_after_accept_penalty' => $this->trustRejectAfterAcceptPenalty,
                'trust_minimum_for_dispatch' => $this->trustMinimumForDispatch,
                'is_enabled' => $this->workerFinanceEnabled,
            ],
        );

        app(DepositService::class)->syncAllWorkerDepositStatuses();

        Notification::make()
            ->title(__('cleaning_admin.financial.saved'))
            ->success()
            ->send();
    }
}
