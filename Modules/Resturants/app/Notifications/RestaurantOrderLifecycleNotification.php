<?php

declare(strict_types=1);

namespace Modules\Resturants\Notifications;

use DevKandil\NotiFire\Enums\MessagePriority;
use DevKandil\NotiFire\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Resturants\Models\Order;

final class RestaurantOrderLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Order $order,
        private readonly string $targetRole,
        private readonly string $event,
        private readonly ?string $fromStatus = null,
        private readonly ?string $toStatus = null,
        private readonly ?string $actorRole = null,
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
        return $this->payload();
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $payload = $this->payload();
        $priority = ($payload['priority'] ?? 'normal') === 'high'
            ? MessagePriority::HIGH
            : MessagePriority::NORMAL;

        return FcmMessage::create((string) $payload['title'], (string) $payload['body'])
            ->priority($priority)
            ->data($this->scalarData($payload));
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        [$title, $body] = $this->copy();
        $canonicalType = $this->canonicalType();
        $legacyType = str_replace('.', '_', $canonicalType);
        $priority = $this->priority();
        $data = $this->data();

        return [
            'type' => $legacyType,
            'canonical_type' => $canonicalType,
            'canonicalType' => $canonicalType,
            'module' => 'restaurant',
            'category' => 'orders',
            'priority' => $priority,
            'icon' => url('/images/notifications/restaurant.svg'),
            'title' => $title,
            'body' => $body,
            'message' => $body,
            'data' => $data,
            ...$data,
        ];
    }

    private function canonicalType(): string
    {
        $prefix = $this->targetRole === 'owner' ? 'restaurant.owner.order' : 'restaurant.order';

        return $prefix.'.'.$this->event;
    }

    /** @return array{0: string, 1: string} */
    private function copy(): array
    {
        $orderNumber = (string) $this->order->order_number;
        $merchantName = (string) ($this->order->restaurant?->name ?: 'المطعم');
        $customerName = (string) ($this->order->user?->name ?: 'عميل');
        $statusLabel = $this->statusLabel($this->toStatus ?? $this->statusValue());

        if ($this->event === 'created' && $this->targetRole === 'owner') {
            return [
                'طلب جديد للمطعم',
                "وصل طلب جديد رقم {$orderNumber} من {$customerName} ويحتاج متابعة.",
            ];
        }

        if ($this->event === 'created') {
            return [
                'تم إنشاء طلب المطعم',
                "تم إنشاء طلبك رقم {$orderNumber} لدى {$merchantName} وهو قيد الانتظار.",
            ];
        }

        if ($this->targetRole === 'owner') {
            return [
                'تحديث حالة طلب مطعم',
                "تم تحديث الطلب رقم {$orderNumber} إلى: {$statusLabel}.",
            ];
        }

        return [
            'تحديث حالة طلب المطعم',
            "تم تحديث طلبك رقم {$orderNumber} لدى {$merchantName} إلى: {$statusLabel}.",
        ];
    }

    private function priority(): string
    {
        return in_array($this->toStatus, ['accepted', 'ready_for_pickup', 'completed', 'cancelled'], true) || $this->event === 'created'
            ? 'high'
            : 'normal';
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $status = $this->toStatus ?? $this->statusValue();
        $deepLinkTarget = $this->targetRole === 'owner'
            ? 'restaurant_owner_order_details'
            : 'restaurant_order_details';

        return array_filter([
            'orderId' => (int) $this->order->id,
            'order_id' => (int) $this->order->id,
            'orderNumber' => (string) $this->order->order_number,
            'order_number' => (string) $this->order->order_number,
            'merchantId' => (int) $this->order->restaurant_id,
            'merchantName' => (string) ($this->order->restaurant?->name ?: ''),
            'status' => $status,
            'statusLabel' => $this->statusLabel($status),
            'fromStatus' => $this->fromStatus,
            'from_status' => $this->fromStatus,
            'actorRole' => $this->actorRole,
            'actor_role' => $this->actorRole,
            'targetRole' => $this->targetRole,
            'target_role' => $this->targetRole,
            'action' => $this->event,
            'deep_link_target' => $deepLinkTarget,
            'deepLinkTarget' => $deepLinkTarget,
            'occurred_at' => now()->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function statusValue(): string
    {
        return $this->order->status?->value ?? (string) $this->order->status;
    }

    private function statusLabel(string $status): string
    {
        return [
            'pending' => 'قيد الانتظار',
            'accepted' => 'تم قبول الطلب',
            'preparing' => 'قيد التحضير',
            'ready_for_pickup' => 'جاهز للاستلام',
            'picked_up' => 'تم الاستلام',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
        ][$status] ?? str_replace('_', ' ', $status);
    }

    /** @param array<string, mixed> $payload */
    private function scalarData(array $payload): array
    {
        $data = [
            'type' => $payload['type'] ?? null,
            'canonical_type' => $payload['canonical_type'] ?? null,
            'canonicalType' => $payload['canonicalType'] ?? null,
            'module' => $payload['module'] ?? null,
            'category' => $payload['category'] ?? null,
            'priority' => $payload['priority'] ?? null,
        ];

        $extra = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return array_filter(
            [...$data, ...$extra],
            fn (mixed $value): bool => is_scalar($value) || $value === null
        );
    }
}
