<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\UserNotificationRequests\UserNotificationIndexRequest;
use App\Http\Resources\UserNotificationResource;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

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

        return UserNotificationResource::collection($notifications)
            ->additional([
                'countUnread' => $countUnread,
            ]);
    }

    public function markAsRead(string $id): Response
    {
        $notification = auth()->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->noContent();
    }

    public function markAllAsRead(): Response
    {
        auth()->user()->unreadNotifications->markAsRead();

        return response()->noContent();
    }

    public function destroy(string $id): Response
    {
        $notification = auth()->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->delete();

        return response()->noContent();
    }

    public function destroyAll(): Response
    {
        auth()->user()->notifications()->delete();

        return response()->noContent();
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
