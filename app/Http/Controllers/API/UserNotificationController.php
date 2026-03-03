<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Requests\UserNotificationRequests\UserNotificationIndexRequest;
use App\Http\Resources\UserNotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class UserNotificationController
{
    public function index(UserNotificationIndexRequest $request): AnonymousResourceCollection
    {
        $query = auth()->user()->notifications()->getQuery();

        if ($request->boolean('filter.unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->orderByDesc('created_at')
            ->paginate($request->get('perPage', 10));

        return UserNotificationResource::collection($notifications);
    }

    public function markAsRead(string $id): Response
    {
        $notification = auth()->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->noContent();
    }
}
