<?php

declare(strict_types=1);

use App\Filament\Pages\FinancialSettings;
use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Livewire\Livewire;
use Modules\Cleaning\Support\CleaningFinancialDefaults;
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

it('persists the allowed debt limit and removes the legacy minimum deposit threshold', function (): void {
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
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 250,
        'restriction_threshold_percent' => 100,
        'trust_reject_after_accept_penalty' => 12,
        'trust_minimum_for_dispatch' => 60,
        'is_enabled' => true,
    ]);
});

it('persists unit and regular and deep time for every app room size', function (): void {
    CleaningFinancialSetting::query()->create([
        'default_commission_rate' => 5,
        'vat_rate' => 10,
        'travel_markup_type' => 'fixed',
        'travel_markup_value' => 2000,
        'travel_per_km' => 100,
        'travel_distance_start_point' => 'worker_home',
        'coverage_thresholds' => ['low' => 2, 'ok' => 5],
        'time_billing_mode' => 'actual',
        'extension_rate_per_30_minutes' => 0,
        'cleaning_room_pricing_units' => CleaningFinancialDefaults::roomPricingUnits(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    Livewire::test(FinancialSettings::class)
        ->set('roomPricingSettings.bedroom.small.pricingUnit', 1.25)
        ->set('roomPricingSettings.bedroom.small.regularMinutes', 31)
        ->set('roomPricingSettings.bedroom.small.deepMinutes', 62)
        ->call('save')
        ->assertHasNoErrors();

    $setting = CleaningFinancialSetting::query()->findOrFail(1);

    expect((float) data_get($setting->cleaning_room_pricing_units, 'bedroom.small'))->toBe(1.25)
        ->and((int) data_get($setting->cleaning_room_time_minutes, 'bedroom.small.regular'))->toBe(31)
        ->and((int) data_get($setting->cleaning_room_time_minutes, 'bedroom.small.deep'))->toBe(62);
});

it('rejects incomplete room size settings', function (): void {
    Livewire::test(FinancialSettings::class)
        ->set('roomPricingSettings.bedroom', [])
        ->call('save')
        ->assertHasErrors(['roomPricingSettings.bedroom']);
});
