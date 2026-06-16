<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use App\Http\Resources\UserNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Delivery\Http\Requests\Driver\DriverNotificationIndexRequest;

final class DriverNotificationController
{
    public function index(DriverNotificationIndexRequest $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications()->getQuery();

        if ($request->boolean('filter.unread')) {
            $query->whereNull('read_at');
        }

        $perPage = (int) $request->integer('perPage', 10);

        return UserNotificationResource::collection(
            $query->orderByDesc('created_at')->paginate($perPage),
        );
    }

    public function markAsRead(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'data' => ['ok' => true],
        ]);
    }
}
