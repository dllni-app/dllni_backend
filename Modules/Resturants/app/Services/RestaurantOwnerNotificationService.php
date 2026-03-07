<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Enums\SystemAlertStatus;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

final class RestaurantOwnerNotificationService
{
    public function feed(
        User $owner,
        Restaurant $restaurant,
        string $tab = 'all',
        bool $unreadOnly = false,
        int $perPage = 15,
        int $page = 1
    ): array {
        $userNotifications = $this->ownerUserNotifications($owner)->map(
            fn (DatabaseNotification $notification): array => $this->mapUserNotification($notification)
        );

        $systemAlerts = $this->ownerSystemAlerts($restaurant)->map(
            fn (SystemAlert $alert): array => $this->mapSystemAlert($alert)
        );

        $all = $userNotifications->concat($systemAlerts)->sortByDesc('createdAt')->values();
        $counters = $this->buildCounters($all);

        $filtered = $all
            ->when($tab !== 'all', fn (Collection $items) => $items->where('category', $tab)->values())
            ->when($unreadOnly, fn (Collection $items) => $items->where('isRead', false)->values());

        $total = $filtered->count();
        $offset = max(0, ($page - 1) * $perPage);
        $data = $filtered->slice($offset, $perPage)->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => (int) ceil($total / max($perPage, 1)),
                'unreadTotal' => $counters['unreadTotal'],
                'tabCounts' => $counters['tabCounts'],
            ],
        ];
    }

    public function markAsRead(User $owner, Restaurant $restaurant, string $notificationId): void
    {
        if (str_starts_with($notificationId, 'user:')) {
            $id = mb_substr($notificationId, 5);
            $notification = $owner->notifications()->where('id', $id)->firstOrFail();
            $notification->markAsRead();

            return;
        }

        if (str_starts_with($notificationId, 'system:')) {
            $id = (int) mb_substr($notificationId, 7);
            $restaurantOrderIds = Order::query()
                ->where('restaurant_id', $restaurant->id)
                ->pluck('id');

            $alert = SystemAlert::query()
                ->where('id', $id)
                ->where('booking_type', Order::class)
                ->whereIn('booking_id', $restaurantOrderIds)
                ->firstOrFail();

            if ($alert->status === SystemAlertStatus::New) {
                $alert->update(['status' => SystemAlertStatus::Acknowledged->value]);
            }
        }
    }

    public function markAllAsRead(User $owner, Restaurant $restaurant, string $tab = 'all'): void
    {
        $notifications = $this->ownerUserNotifications($owner)
            ->filter(fn (DatabaseNotification $notification): bool => $tab === 'all' || $this->resolveCategory($notification->data) === $tab);

        foreach ($notifications as $notification) {
            if (! $notification->read_at) {
                $notification->markAsRead();
            }
        }

        $alertsQuery = SystemAlert::query()
            ->where('status', SystemAlertStatus::New)
            ->where('booking_type', Order::class)
            ->whereIn(
                'booking_id',
                Order::query()->where('restaurant_id', $restaurant->id)->select('id')
            );

        if ($tab !== 'all' && $tab !== 'system') {
            return;
        }

        $alertsQuery->update(['status' => SystemAlertStatus::Acknowledged->value]);
    }

    /** @return EloquentCollection<int, DatabaseNotification> */
    private function ownerUserNotifications(User $owner): EloquentCollection
    {
        return $owner->notifications()->orderByDesc('created_at')->get();
    }

    /** @return EloquentCollection<int, SystemAlert> */
    private function ownerSystemAlerts(Restaurant $restaurant): EloquentCollection
    {
        $restaurantOrderIds = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->pluck('id');

        return SystemAlert::query()
            ->with('booking')
            ->where('booking_type', Order::class)
            ->whereIn('booking_id', $restaurantOrderIds)
            ->orderByDesc('created_at')
            ->get();
    }

    private function mapUserNotification(DatabaseNotification $notification): array
    {
        $data = (array) ($notification->data ?? []);

        return [
            'id' => 'user:'.$notification->id,
            'source' => 'user_notification',
            'category' => $this->resolveCategory($data),
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'meta' => [
                'type' => $data['type'] ?? null,
                'data' => $data,
            ],
            'createdAt' => $notification->created_at?->toIso8601String(),
            'isRead' => $notification->read_at !== null,
        ];
    }

    private function mapSystemAlert(SystemAlert $alert): array
    {
        $order = $alert->booking instanceof Order ? $alert->booking : null;
        $orderNumber = $order?->order_number;

        return [
            'id' => 'system:'.$alert->id,
            'source' => 'system_alert',
            'category' => 'system',
            'title' => 'System alert',
            'body' => $orderNumber ? 'Order '.$orderNumber.' requires attention.' : 'A system alert requires attention.',
            'meta' => [
                'alertType' => $alert->alert_type?->value ?? $alert->alert_type,
                'severity' => $alert->severity?->value ?? $alert->severity,
                'status' => $alert->status?->value ?? $alert->status,
                'payload' => $alert->payload,
                'orderId' => $order?->id,
                'orderNumber' => $orderNumber,
            ],
            'createdAt' => $alert->created_at?->toIso8601String(),
            'isRead' => $alert->status !== SystemAlertStatus::New,
        ];
    }

    private function resolveCategory(array $data): string
    {
        $type = mb_strtolower((string) ($data['type'] ?? ''));

        if (str_contains($type, 'offer') || str_contains($type, 'coupon') || str_contains($type, 'promo')) {
            return 'offers';
        }

        if (str_contains($type, 'order')) {
            return 'orders';
        }

        return 'system';
    }

    /** @param Collection<int, array{id: string, category: string, isRead: bool}> $items */
    private function buildCounters(Collection $items): array
    {
        $tabCounts = [
            'all' => $items->count(),
            'orders' => $items->where('category', 'orders')->count(),
            'offers' => $items->where('category', 'offers')->count(),
            'system' => $items->where('category', 'system')->count(),
        ];

        return [
            'unreadTotal' => $items->where('isRead', false)->count(),
            'tabCounts' => $tabCounts,
        ];
    }
}
