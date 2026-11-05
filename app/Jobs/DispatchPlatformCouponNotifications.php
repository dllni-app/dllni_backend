<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformCoupon;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchPlatformCouponNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $couponId) {}

    public function handle(): void
    {
        $coupon = PlatformCoupon::query()->with('users:id')->find($this->couponId);
        if (! $coupon) {
            return;
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereNull('module_type')
            ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', [
                'admin',
                'Super Admin',
                'Cleaning Ops Manager',
                'Customer Support',
                'Onboarding Specialist',
                'Accountant',
                'delivery_company_admin',
                'delivery_company_staff',
            ]));

        if ($coupon->audience_type === PlatformCoupon::AUDIENCE_SPECIFIC_USERS) {
            $query->whereKey($coupon->users->modelKeys());
        }

        $query->select(['id', 'name', 'email', 'phone', 'fcm_token'])->chunkById(500, function ($users) use ($coupon): void {
            foreach ($users as $user) {
                // Reuse the idempotent path also used when a user verifies an account.
                // A retry or the second dispatch path must not create a new UUID
                // for a coupon the same customer has already received.
                (new DispatchAvailablePlatformCouponNotificationsToUser((int) $user->id))
                    ->notifyOnce($user, $coupon);
            }
        });

        $coupon->forceFill(['notification_sent_at' => now()])->saveQuietly();
    }
}
