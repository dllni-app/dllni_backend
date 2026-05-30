<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Http\Requests\Driver\DriverLoginRequest;
use Modules\Delivery\Http\Resources\DeliveryDriverResource;
use Modules\Delivery\Models\DeliveryDriver;

final class DriverAuthController
{
    public function login(DriverLoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid credentials'],
            ]);
        }

        $driver = DeliveryDriver::query()
            ->with(['company', 'user'])
            ->where('user_id', $user->id)
            ->first();

        if (! $driver) {
            return response()->json([
                'message' => 'This account is not linked to a delivery driver profile.',
            ], 403);
        }

        $fcmToken = $request->validated('fcmToken');
        if (is_string($fcmToken) && $fcmToken !== '') {
            $user->forceFill(['fcm_token' => $fcmToken])->saveQuietly();
        }

        $token = $user->createToken('delivery-driver-api')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => UserResource::make($user),
                'driver' => DeliveryDriverResource::make($driver),
            ],
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $driver = DeliveryDriver::query()
            ->with(['company', 'user'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'data' => DeliveryDriverResource::make($driver),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
