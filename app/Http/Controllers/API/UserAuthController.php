<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\UserModuleType;
use App\Http\Requests\Auth\UserLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\RestaurantSellerAuthExtras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserAuthController
{
    public function login(UserLoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'phone' => [__('auth.failed')],
            ]);
        }

        $user->loadMissing('worker');
        $token = $user->createToken('user-api')->plainTextToken;

        $payload = [
            'user' => UserResource::make($user),
            'token' => $token,
        ];

        if ($user->module_type === UserModuleType::RestaurantSeller) {
            $payload['role'] = RestaurantSellerAuthExtras::rolePayload($user);
            $payload['permissions'] = RestaurantSellerAuthExtras::permissionsPayload($user);
        }

        return response()->json($payload);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('auth.logout'),
        ]);
    }
}
