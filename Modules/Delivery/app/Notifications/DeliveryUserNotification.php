<?php

declare(strict_types=1);

namespace Modules\Delivery\Notifications;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class DeliveryUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $data,
        private readonly string $priority = 'high',
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        $fcmToken = is_callable([$notifiable, 'routeNotificationForFcm'])
            ? $notifiable->routeNotificationForFcm(null)
            : null;

        if (is_string($fcmToken) && $fcmToken !== '') {
            $channels[] = 'fcm';
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $payload = $this->payload();

        return [
            'type' => 'delivery_user_order_update',
            'canonical_type' => 'delivery.user.order_update',
            'canonicalType' => 'delivery.user.order_update',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => $this->priority,
            'title' => $this->title,
            'body' => $this->body,
            'message' => $this->body,
            'data' => $payload,
            ...$payload,
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $priority = mb_strtolower($this->priority) === 'high'
            ? MessagePriority::HIGH
            : MessagePriority::NORMAL;

        return FcmMessage::create($this->title, $this->body)
            ->priority($priority)
            ->data($this->serializeDataPayload());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $payload = $this->data;
        $payload['deep_link_target'] = $payload['deepLinkTarget'] ?? $payload['deep_link_target'] ?? 'delivery_order_tracking';
        $payload['deepLinkTarget'] = $payload['deepLinkTarget'] ?? $payload['deep_link_target'];
        $payload['module'] = 'delivery';

        return $payload;
    }

    /** @return array<string, string> */
    private function serializeDataPayload(): array
    {
        return collect([
            'type' => 'delivery_user_order_update',
            'canonical_type' => 'delivery.user.order_update',
            'canonicalType' => 'delivery.user.order_update',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => $this->priority,
            ...$this->payload(),
        ])
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value === null)
            ->map(fn (mixed $value): string => $value === null ? '' : (string) $value)
            ->all();
    }
}
