<?php

declare(strict_types=1);

use App\Filament\Resources\PlatformCoupons\Pages\CreatePlatformCoupon;
use App\Filament\Resources\PlatformCoupons\PlatformCouponResource;
use App\Jobs\DispatchPlatformCouponNotifications;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();

    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('creates a shared funded platform coupon and normalizes the merchant share', function (): void {
    Livewire::test(CreatePlatformCoupon::class)
        ->fillForm([
            'code' => 'SHARED70',
            'section' => PlatformCoupon::SECTION_RESTAURANT,
            'title_ar' => 'خصم مشترك',
            'description_ar' => 'تتحمل المنصة سبعين بالمئة',
            'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
            'discount_value' => 200,
            'funding_type' => PlatformCoupon::FUNDING_SHARED,
            'platform_funding_percent' => 70,
            'max_platform_funding_amount' => 150,
            'cap_platform_funding_to_revenue' => true,
            'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
            'per_user_usage_limit' => 1,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = PlatformCoupon::query()->where('code', 'SHARED70')->firstOrFail();

    expect($coupon->funding_type)->toBe(PlatformCoupon::FUNDING_SHARED)
        ->and((float) $coupon->platform_funding_percent)->toBe(70.0)
        ->and((float) $coupon->merchant_funding_percent)->toBe(30.0)
        ->and((float) $coupon->max_platform_funding_amount)->toBe(150.0)
        ->and($coupon->cap_platform_funding_to_revenue)->toBeTrue();

    Queue::assertPushed(DispatchPlatformCouponNotifications::class);
});

it('shows active funding totals without counting reversed redemptions', function (): void {
    $coupon = PlatformCoupon::query()->create([
        'code' => 'FUNDINGVIEW',
        'title_ar' => 'عرض التمويل',
        'description_ar' => 'عرض التمويل',
        'section' => PlatformCoupon::SECTION_SUPERMARKET,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 100,
        'funding_type' => PlatformCoupon::FUNDING_SHARED,
        'platform_funding_percent' => 70,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'is_active' => true,
    ]);

    $user = User::factory()->create();

    PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'section' => PlatformCoupon::SECTION_SUPERMARKET,
        'order_type' => 'supermarket_order',
        'order_id' => 1001,
        'coupon_code' => $coupon->code,
        'subtotal' => 1000,
        'discount_amount' => 100,
        'funding_type' => PlatformCoupon::FUNDING_SHARED,
        'platform_funded_amount' => 70,
        'merchant_funded_amount' => 30,
        'redeemed_at' => now(),
    ]);

    PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $coupon->id,
        'user_id' => $user->id,
        'section' => PlatformCoupon::SECTION_SUPERMARKET,
        'order_type' => 'supermarket_order',
        'order_id' => 1002,
        'coupon_code' => $coupon->code,
        'subtotal' => 1000,
        'discount_amount' => 50,
        'funding_type' => PlatformCoupon::FUNDING_PLATFORM,
        'platform_funded_amount' => 50,
        'merchant_funded_amount' => 0,
        'redeemed_at' => now(),
        'reversed_at' => now(),
        'reversal_reason' => 'Cancelled',
    ]);

    $this->get(PlatformCouponResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('FUNDINGVIEW')
        ->assertSee('مشترك')
        ->assertSee('70.00 ل.س')
        ->assertSee('30.00 ل.س');
});
