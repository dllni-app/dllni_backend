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

it('removes global worker finance controls and persists only shared trust settings', function (): void {
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

    $this->get(FinancialSettings::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('الحد الأدنى لسعر طلب التنظيف')
        ->assertSee('معامل التنظيف العميق')
        ->assertSee('سعر الساعة للعامل الواحد')
        ->assertDontSee('الدين الإداري يضاف إلى رصيد الإيداع')
        ->assertDontSee('تفعيل قواعد مالية العاملين')
        ->assertDontSee('حد المديونية الافتراضي');

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
        ->set('eventAssistanceHourlyRatePerWorker', 400)
        ->set('trustRejectAfterAcceptPenalty', 12)
        ->set('trustMinimumForDispatch', 60)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('cleaning_financial_settings', [
        'id' => 1,
        'extension_rate_per_30_minutes' => 200,
    ]);

    $this->assertDatabaseHas('cleaning_deposit_settings', [
        'minimum_deposit_amount' => 0,
        'restriction_threshold_percent' => 100,
        'trust_reject_after_accept_penalty' => 12,
        'trust_minimum_for_dispatch' => 60,
    ]);
});

it('persists the per-worker transport allowance travel markup option', function (): void {
    $this->get(FinancialSettings::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('رسوم حسب المسافة')
        ->assertSee('بدل مواصلات لكل عامل');

    Livewire::test(FinancialSettings::class)
        ->set('travelMarkupType', 'worker_allowance')
        ->assertSee('يُحتسب بدل المواصلات تلقائياً لكل عامل ضمن تسعير الطلب،')
        ->assertDontSee('رسوم التنقل لكل كيلومتر')
        ->set('travelMarkupValue', 125)
        ->set('travelPerKm', 999)
        ->call('save')
        ->assertHasNoErrors();

    $setting = CleaningFinancialSetting::query()->findOrFail(1);

    expect($setting->travel_markup_type)->toBe('worker_allowance')
        ->and((float) $setting->travel_markup_value)->toBe(125.0)
        ->and((float) $setting->travel_per_km)->toBe(0.0);

    Livewire::test(FinancialSettings::class)
        ->set('travelMarkupType', 'percent')
        ->call('save')
        ->assertHasErrors(['travelMarkupType']);
});

it('persists minimum order price and room pricing, deep multiplier, and times', function (): void {
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
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    Livewire::test(FinancialSettings::class)
        ->set('cleaningMinimumOrderPrice', 150)
        ->set('roomPricingSettings.bedroom.small.pricingUnit', 1.25)
        ->set('roomPricingSettings.bedroom.small.deepMultiplier', 2.75)
        ->set('roomPricingSettings.bedroom.small.regularMinutes', 31)
        ->set('roomPricingSettings.bedroom.small.deepMinutes', 62)
        ->call('save')
        ->assertHasNoErrors();

    $setting = CleaningFinancialSetting::query()->findOrFail(1);

    expect((float) $setting->cleaning_minimum_order_price)->toBe(150.0)
        ->and((float) data_get($setting->cleaning_room_pricing_units, 'bedroom.small'))->toBe(1.25)
        ->and((float) data_get($setting->cleaning_room_deep_multipliers, 'bedroom.small'))->toBe(2.75)
        ->and((int) data_get($setting->cleaning_room_time_minutes, 'bedroom.small.regular'))->toBe(31)
        ->and((int) data_get($setting->cleaning_room_time_minutes, 'bedroom.small.deep'))->toBe(62);
});

it('persists the user cancellation fee from financial settings', function (): void {
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
        'cleaning_room_deep_multipliers' => CleaningFinancialDefaults::roomDeepMultipliers(),
        'cleaning_room_time_minutes' => CleaningFinancialDefaults::roomTimeMinutes(),
    ]);

    Livewire::test(FinancialSettings::class)
        ->set('userCancellationFee', 17500.25)
        ->call('save')
        ->assertHasNoErrors();

    expect((float) CleaningFinancialSetting::query()->findOrFail(1)->user_cancellation_fee)->toBe(17500.25);
});

it('rejects incomplete room size settings', function (): void {
    Livewire::test(FinancialSettings::class)
        ->set('roomPricingSettings.bedroom', [])
        ->call('save')
        ->assertHasErrors(['roomPricingSettings.bedroom']);
});
