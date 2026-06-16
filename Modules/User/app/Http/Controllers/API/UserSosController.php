<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\Resturants\Models\Order;
use Modules\User\Actions\CreateUserSosAlertAction;
use Modules\User\Http\Requests\UserSosStoreRequest;

final class UserSosController
{
    public function store(UserSosStoreRequest $request, CreateUserSosAlertAction $action): JsonResponse
    {
        $order = Order::query()
            ->whereKey($request->integer('order_id'))
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();

        $sos = $action->execute(
            user: $request->user(),
            order: $order,
            message: $request->string('message')->toString(),
        );

        return response()->json([
            'success' => true,
            'message' => 'SOS request sent successfully.',
            'data' => [
                'id' => $sos->id,
                'order_id' => $sos->order_id,
                'message' => $sos->message,
                'status' => $sos->status?->value ?? $sos->status,
                'created_at' => $sos->created_at?->toISOString(),
            ],
        ], 201);
    }
}
