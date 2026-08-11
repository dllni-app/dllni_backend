<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Notifications\Core\NotificationPayloadBuilder;
use BackedEnum;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;

final class BookingLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $extraData
     * @param  array<string, scalar|null>  $templateContext
     */
    public function __construct(
        private readonly CleaningBooking|EventBooking $booking,
        private readonly string $canonicalType,
        private readonly string $actorRole,
        private readonly string $targetRole,
        private readonly ?string $fromStatus = null,
        private readonly ?string $action = null,
        private readonly ?string $deepLinkTarget = null,
        private readonly ?string $occurredAt = null,
        private readonly array $extraData = [],
        private readonly array $templateContext = [],
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->payloadBuilder()->resolveChannels($this->canonicalType, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payloadBuilder()->makeDatabasePayload(
            canonicalType: $this->canonicalType,
            templateContext: $this->resolvedTemplateContext(),
            extraData: $this->extraData(),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: $this->canonicalType,
            templateContext: $this->resolvedTemplateContext(),
            extraData: $this->extraData(),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function resolvedTemplateContext(): array
    {
        return array_merge([
            'booking_number' => (string) $this->booking->booking_number,
            'status' => $this->localizedStatusValue(),
            'from_status' => $this->fromStatus,
            'action' => $this->action,
            'actor_role' => $this->actorRole,
            'target_role' => $this->targetRole,
        ], $this->templateContext);
    }

    /**
     * @return array<string, mixed>
     */
    private function extraData(): array
    {
        $resolvedAction = $this->action ?? str_replace('cleaning.booking.', '', $this->canonicalType);
        $resolvedDeepLinkTarget = $this->resolvedDeepLinkTarget();
        $bookingType = $this->bookingType();

        return array_filter(array_merge([
            'bookingId' => (int) $this->booking->id,
            'orderId' => (int) $this->booking->id,
            'status' => $this->statusValue(),
            'action' => $resolvedAction,
            'deep_link_target' => $resolvedDeepLinkTarget,
            'occurred_at' => $this->occurredAt ?? now()->toIso8601String(),
            'fromStatus' => $this->fromStatus,
            'actorRole' => $this->actorRole,
            'targetRole' => $this->targetRole,
            'bookingNumber' => (string) $this->booking->booking_number,
            'bookingType' => $bookingType,
            'booking_type' => $bookingType,
        ], $this->extraData), fn (mixed $value): bool => $value !== null);
    }

    private function statusValue(): string
    {
        $status = $this->booking->status;

        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return (string) $status;
    }

    private function localizedStatusValue(): string
    {
        $statusValue = $this->statusValue();
        $status = CleaningBookingStatus::tryFrom($statusValue);

        if (! $status instanceof CleaningBookingStatus) {
            return $statusValue;
        }

        if ($status === CleaningBookingStatus::UnderDispute) {
            return 'قيد النزاع';
        }

        $translationKey = 'cleaning_admin.enums.cleaning_booking_status.'.$statusValue;

        return Lang::has($translationKey, 'ar')
            ? Lang::get($translationKey, [], 'ar')
            : $statusValue;
    }

    private function bookingType(): string
    {
        return $this->booking instanceof EventBooking ? 'event_booking' : 'cleaning_booking';
    }

    private function resolvedDeepLinkTarget(): ?string
    {
        if ($this->deepLinkTarget !== null) {
            return $this->deepLinkTarget;
        }

        if ($this->booking instanceof EventBooking) {
            return null;
        }

        return $this->targetRole === 'worker'
            ? 'cleaning_booking_details'
            : 'cleaning_order_details';
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
