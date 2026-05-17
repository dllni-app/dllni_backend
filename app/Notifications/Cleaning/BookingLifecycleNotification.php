<?php

declare(strict_types=1);

namespace App\Notifications\Cleaning;

use App\Notifications\Core\NotificationPayloadBuilder;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningBooking;

final class BookingLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CleaningBooking $booking,
        private readonly string $canonicalType,
        private readonly string $actorRole,
        private readonly string $targetRole,
        private readonly ?string $fromStatus = null,
    ) {
        $this->onQueue('notifications');
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
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->payloadBuilder()->makeFcmMessage(
            canonicalType: $this->canonicalType,
            templateContext: $this->templateContext(),
            extraData: $this->extraData(),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function templateContext(): array
    {
        return [
            'booking_number' => (string) $this->booking->booking_number,
            'status' => (string) $this->booking->status->value,
            'from_status' => $this->fromStatus,
            'actor_role' => $this->actorRole,
            'target_role' => $this->targetRole,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function extraData(): array
    {
        return array_filter([
            'bookingId' => (int) $this->booking->id,
            'status' => (string) $this->booking->status->value,
            'fromStatus' => $this->fromStatus,
            'actorRole' => $this->actorRole,
            'targetRole' => $this->targetRole,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function payloadBuilder(): NotificationPayloadBuilder
    {
        return app(NotificationPayloadBuilder::class);
    }
}
