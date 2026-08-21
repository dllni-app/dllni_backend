<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

it('seeds required operational defaults for a fresh production database', function (): void {
    config()->set('app.env', 'production');

    $this->seed(DatabaseSeeder::class);

    expect(DB::table('cleaning_financial_settings')->exists())->toBeTrue()
        ->and(DB::table('cleaning_deposit_settings')->exists())->toBeTrue()
        ->and(DB::table('service_addons')->exists())->toBeTrue()
        ->and(DB::table('travel_cost_configs')->exists())->toBeTrue()
        ->and(DB::table('cleaning_home_types')->exists())->toBeTrue()
        ->and(DB::table('cleaning_neighborhoods')->exists())->toBeTrue()
        ->and(DB::table('cleaning_services')->where('slug', 'standard-apartment-cleaning')->exists())->toBeTrue()
        ->and(DB::table('cleaning_services')->where('slug', 'event-assistance')->exists())->toBeTrue();
});

it('does not overwrite existing operational configuration when seeding again', function (): void {
    config()->set('app.env', 'production');

    $this->seed(DatabaseSeeder::class);

    DB::table('cleaning_financial_settings')->where('id', 1)->update([
        'default_commission_rate' => 37.5,
    ]);
    DB::table('service_addons')->where('slug', 'inside-fridge')->update([
        'name' => 'Custom refrigerator service',
    ]);
    DB::table('travel_cost_configs')->where('name', 'المنطقة المحلية')->update([
        'is_active' => false,
    ]);
    DB::table('cleaning_services')->where('slug', 'standard-apartment-cleaning')->update([
        'name' => 'Custom cleaning service',
    ]);
    DB::table('cleaning_home_types')->where('code', 'apartment')->update([
        'title' => 'Custom apartment title',
    ]);
    DB::table('cleaning_neighborhoods')->where('name_ar', 'الجميلية')->update([
        'name_en' => 'Custom Jamiliyah',
    ]);

    $this->seed(DatabaseSeeder::class);

    expect((float) DB::table('cleaning_financial_settings')->where('id', 1)->value('default_commission_rate'))
        ->toBe(37.5)
        ->and(DB::table('service_addons')->where('slug', 'inside-fridge')->value('name'))
        ->toBe('Custom refrigerator service')
        ->and((bool) DB::table('travel_cost_configs')->where('name', 'المنطقة المحلية')->value('is_active'))
        ->toBeFalse()
        ->and(DB::table('cleaning_services')->where('slug', 'standard-apartment-cleaning')->value('name'))
        ->toBe('Custom cleaning service')
        ->and(DB::table('cleaning_home_types')->where('code', 'apartment')->value('title'))
        ->toBe('Custom apartment title')
        ->and(DB::table('cleaning_neighborhoods')->where('name_ar', 'الجميلية')->value('name_en'))
        ->toBe('Custom Jamiliyah');
});
