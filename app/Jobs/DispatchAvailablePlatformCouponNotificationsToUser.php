<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PlatformCoupon;
use App\Models\User;
use App\Notifications\PlatformCouponAvailableNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class DispatchAvailablePlatformCouponNotificationsToUser implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

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
                $this->notifyOnce($user, $coupon);
            });
    }

    private function notifyOnce(User $user, PlatformCoupon $coupon): void
    {
        if ($this->alreadyNotified($user, $coupon)) {
            return;
        }

        $notification = new PlatformCouponAvailableNotification($coupon);
        $notification->id = $this->notificationId((int) $user->id, (int) $coupon->id);

        try {
            // The outer job is already queued. Sending the notification synchronously here keeps
            // the database write and push delivery behind one idempotency boundary instead of
            // fan-out jobs that can independently duplicate on retry.
            $user->notifyNow($notification);
        } catch (QueryException $exception) {
            if ($this->isDuplicateNotificationInsert($exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function alreadyNotified(User $user, PlatformCoupon $coupon): bool
    {
        return $user->notifications()
            ->where('type', PlatformCouponAvailableNotification::class)
            ->get(['data'])
            ->contains(function ($notification) use ($coupon): bool {
                $data = is_array($notification->data) ? $notification->data : [];
                $couponId = data_get($data, 'couponId') ?? data_get($data, 'data.couponId');

                return (string) $couponId === (string) $coupon->id;
            });
    }

    private function notificationId(int $userId, int $couponId): string
    {
        $hex = substr(hash('sha256', "platform-coupon:{$couponId}:user:{$userId}"), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function isDuplicateNotificationInsert(QueryException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return in_array((string) $exception->getCode(), ['23000', '23505'], true)
            && (Str::contains($message, 'duplicate') || Str::contains($message, 'unique'));
    }
}
