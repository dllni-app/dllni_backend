<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformCoupon;
use App\Models\User;
use App\Notifications\PlatformCouponAvailableNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchAvailablePlatformCouponNotificationsToUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $user = User::query()->find($this->userId);
        if (! $user || ! (bool) ($user->is_active ?? true)) {
            return;
        }

        PlatformCoupon::query()
            ->currentlyActive()
            ->forUser($user->id)
            ->where(function ($query) use ($user): void {
                $query->whereNull('per_user_usage_limit')
                    ->orWhereRaw(
                        '(select count(*) from platform_coupon_redemptions where platform_coupon_redemptions.platform_coupon_id = platform_coupons.id and platform_coupon_redemptions.user_id = ?) < platform_coupons.per_user_usage_limit',
                        [$user->id]
                    );
            })
            ->orderByRaw('expires_at is null')
            ->orderBy('expires_at')
            ->orderByDesc('id')
            ->each(function (PlatformCoupon $coupon) use ($user): void {
                $user->notify(new PlatformCouponAvailableNotification($coupon));
            });
    }
}
