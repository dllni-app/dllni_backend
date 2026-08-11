<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PlatformCoupon;
use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class PlatformCouponAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly PlatformCoupon $coupon)
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->builder()->resolveChannels('marketing.coupon.available', $notifiable);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return $this->builder()->makeDatabasePayload(
            canonicalType: 'marketing.coupon.available',
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->builder()->makeFcmMessage(
            canonicalType: 'marketing.coupon.available',
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    /** @return array<string, scalar|null> */
    private function templateContext(): array
    {
        return [
            'description' => $this->coupon->localizedDescription('ar'),
            'coupon_code' => $this->coupon->code,
        ];
    }

    /** @return array<string, mixed> */
    private function extraData(): array
    {
        return [
            'couponId' => $this->coupon->id,
            'couponCode' => $this->coupon->code,
            'section' => $this->coupon->section,
            'expiresAt' => $this->coupon->expires_at?->toIso8601String(),
            'deep_link_target' => 'coupons',
        ];
    }

    private function builder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
