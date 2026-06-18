<?php

declare(strict_types=1);

use App\Filament\Pages\FinancialSettings;
use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'financial-settings-admin@example.com',
    ]);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('persists extension_rate_per_30_minutes from financial settings page', function (): void {
    CleaningFinancialSetting::query()->create([
        'default_commission_rate' => 5,
        'vat_rate' => 10,
        'travel_markup_type' => 'fixed',
        'travel_markup_value' => 2000,
        'travel_per_km' => 100,
        'travel_distance_start_point' => 'worker_home',
        'coverage_thresholds' => ['low' => 2, 'ok' => 5],
        'time_billing_mode' => 'actual',
        'min_billable_minutes' => 30,
        'time_warning_minutes_before_end' => 10,
        'extension_rate_per_30_minutes' => 0,
    ]);

    $this->get(FinancialSettings::getUrl([], isAbsolute: false))->assertSuccessful();

    Livewire::test(FinancialSettings::class)
        ->set('defaultCommissionRate', 5)
        ->set('vatRate', 10)
        ->set('commissionType', 'percent')
        ->set('travelMarkupType', 'fixed')
        ->set('travelMarkupValue', 2000)
        ->set('travelPerKm', 100)
        ->set('travelDistanceStartPoint', 'worker_home')
        ->set('coverageLow', 2)
        ->set('coverageOk', 5)
        ->set('timeBillingMode', 'actual')
        ->set('minBillableMinutes', 30)
        ->set('timeWarningMinutesBeforeEnd', 10)
        ->set('extensionRatePer30Minutes', 4500.50)
        ->set('minimumDepositAmount', 1500)
        ->set('defaultMaxNegativeBalance', 250)
        ->set('trustRejectAfterAcceptPenalty', 12)
        ->set('trustMinimumForDispatch', 60)
        ->set('workerFinanceEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cleaning_financial_settings', [
        'id' => 1,
        'extension_rate_per_30_minutes' => 4500.50,
    ]);

    $this->assertDatabaseHas('cleaning_deposit_settings', [
        'minimum_deposit_amount' => 1500,
        'default_max_negative_balance' => 250,
        'trust_reject_after_accept_penalty' => 12,
        'trust_minimum_for_dispatch' => 60,
        'is_enabled' => true,
    ]);
});
