<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\UserNotificationRequests\UserNotificationIndexRequest;
use App\Http\Resources\UserNotificationResource;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

final class UserNotificationController
{
    public function index(UserNotificationIndexRequest $request): AnonymousResourceCollection
    {
        $user = auth()->user();
        $query = $user->notifications()->getQuery();

        $this->excludeUnavailableNewOrderNotifications($query);

        $countUnread = (clone $query)
            ->whereNull('read_at')
            ->count();

        if ($request->boolean('filter.unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderByDesc('created_at')
            ->paginate($request->get('perPage', 10));

        $this->markPageDelivered($notifications->getCollection()->all());

        return UserNotificationResource::collection($notifications)
            ->additional([
                'countUnread' => $countUnread,
            ]);
    }

    public function markAsDelivered(string $id): Response
    {
        $notification = $this->notificationForCurrentUser($id);

        if ($notification->getAttribute('delivered_at') === null) {
            $notification->forceFill(['delivered_at' => now()])->save();
        }

        return response()->noContent();
    }

    public function markAsViewed(string $id): Response
    {
        $this->markNotificationViewed($this->notificationForCurrentUser($id));

        return response()->noContent();
    }

    public function markAsRead(string $id): Response
    {
        $this->markNotificationViewed($this->notificationForCurrentUser($id));

        return response()->noContent();
    }

    public function markAllAsRead(): Response
    {
        $notifications = auth()->user()->notifications();
        $now = now();

        (clone $notifications)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $now]);
        (clone $notifications)
            ->whereNull('viewed_at')
            ->update(['viewed_at' => $now]);
        (clone $notifications)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        return response()->noContent();
    }

    public function destroy(string $id): Response
    {
        $notification = $this->notificationForCurrentUser($id);
        $notification->delete();

        return response()->noContent();
    }

    public function destroyAll(): Response
    {
        auth()->user()->notifications()->delete();

        return response()->noContent();
    }

    /** @param array<int, DatabaseNotification> $notifications */
    private function markPageDelivered(array $notifications): void
    {
        $ids = collect($notifications)
            ->filter(fn (mixed $notification): bool => $notification instanceof DatabaseNotification)
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $now = now();
        auth()->user()->notifications()
            ->whereIn('id', $ids)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => $now]);

        foreach ($notifications as $notification) {
            if (! $notification instanceof DatabaseNotification) {
                continue;
            }

            if ($notification->getAttribute('delivered_at') === null) {
                $notification->setAttribute('delivered_at', $now);
            }
        }
    }

    private function markNotificationViewed(DatabaseNotification $notification): void
    {
        $now = now();

        $notification->forceFill([
            'delivered_at' => $notification->getAttribute('delivered_at') ?? $now,
            'viewed_at' => $notification->getAttribute('viewed_at') ?? $now,
            'read_at' => $notification->read_at ?? $now,
        ])->save();
    }

    private function notificationForCurrentUser(string $id): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = auth()->user()->notifications()->where('id', $id)->firstOrFail();

        return $notification;
    }

    private function excludeUnavailableNewOrderNotifications(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->where('type', '!=', NewOrderRequestNotification::class)
                ->orWhere(function (Builder $query): void {
                    $query->where('type', NewOrderRequestNotification::class)
                        ->where(function (Builder $query): void {
                            $query->whereNull('data->state')
                                ->orWhere('data->state', '!=', 'unavailable');
                        })
                        ->where(function (Builder $query): void {
                            $query->whereNull('data->data->state')
                                ->orWhere('data->data->state', '!=', 'unavailable');
                        });
                });
        });
    }
}
