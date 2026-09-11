<?php

declare(strict_types=1);

use App\Jobs\DispatchAvailablePlatformCouponNotificationsToUser;
use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Models\User;
use App\Notifications\PlatformCouponAvailableNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('queues available coupon notifications after a new account is verified', function (): void {
    Queue::fake();

    $phone = '+963944009901';

    $this->postJson('/api/v1/user/register', [
        'name' => 'Coupon Verification User',
        'phone' => $phone,
        'password' => 'password123',
    ])->assertOk();

    $user = User::query()->where('phone', $phone)->firstOrFail();
    $otp = Cache::get(sprintf('user_otp_plain:%s:%s', 'register', $phone));
    expect($otp)->toBeString();

    $this->postJson('/api/v1/user/verify-account', [
        'phone' => $phone,
        'otp' => $otp,
    ])->assertOk();

    Queue::assertPushed(
        DispatchAvailablePlatformCouponNotificationsToUser::class,
        fn (DispatchAvailablePlatformCouponNotificationsToUser $job): bool => $job->userId === $user->id,
    );
});

it('notifies the user only about coupons that are currently available to them', function (): void {
    Notification::fake();

    $user = User::factory()->create(['is_active' => true]);
    $otherUser = User::factory()->create(['is_active' => true]);

    PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'WELCOME10',
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
    ]));

    $targeted = PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'TARGET15',
        'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
    ]));
    $targeted->users()->attach($user->id);

    $otherTargeted = PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'OTHER15',
        'audience_type' => PlatformCoupon::AUDIENCE_SPECIFIC_USERS,
    ]));
    $otherTargeted->users()->attach($otherUser->id);

    PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'EXPIRED10',
        'expires_at' => now()->subMinute(),
    ]));

    PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'FUTURE10',
        'starts_at' => now()->addHour(),
    ]));

    $usedCoupon = PlatformCoupon::query()->create(newUserCouponData([
        'code' => 'USED10',
        'per_user_usage_limit' => 1,
    ]));

    PlatformCouponRedemption::query()->create([
        'platform_coupon_id' => $usedCoupon->id,
        'user_id' => $user->id,
        'section' => PlatformCoupon::SECTION_CLEANING,
        'order_type' => 'test-order',
        'order_id' => 1,
        'coupon_code' => $usedCoupon->code,
        'subtotal' => 100,
        'discount_amount' => 10,
        'redeemed_at' => now(),
    ]);

    (new DispatchAvailablePlatformCouponNotificationsToUser($user->id))->handle();

    expect(Notification::sent($user, PlatformCouponAvailableNotification::class))->toHaveCount(2);
});

/** @return array<string, mixed> */
function newUserCouponData(array $overrides = []): array
{
    return array_replace([
        'code' => 'NEWUSER10',
        'title_ar' => 'كوبون متاح',
        'title_en' => 'Available coupon',
        'description_ar' => 'كوبون متاح للمستخدم',
        'description_en' => 'Coupon available to the user',
        'section' => PlatformCoupon::SECTION_ALL,
        'discount_type' => PlatformCoupon::DISCOUNT_FIXED,
        'discount_value' => 10,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => 1,
        'used_count' => 0,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ], $overrides);
}
