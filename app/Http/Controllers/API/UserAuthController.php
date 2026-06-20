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
    private const INVALID_CREDENTIALS_MESSAGE = 'رقم الهاتف أو كلمة المرور غير صحيحة.';
    private const ACCOUNT_NOT_ACTIVE_MESSAGE = 'الحساب غير مفعل حالياً. يرجى التواصل مع الدعم.';
    private const MODULE_ACCESS_DENIED_MESSAGE = 'هذا الحساب غير مصرح له بتسجيل الدخول إلى هذا التطبيق.';
    private const LOGOUT_SUCCESS_MESSAGE = 'تم تسجيل الخروج بنجاح.';

    public function login(UserLoginRequest $request): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user instanceof User || ! Hash::check($request->validated('password'), $user->password)) {
            $this->throwValidationError('phone', self::INVALID_CREDENTIALS_MESSAGE);
        }

        if (! $user->is_active) {
            $this->throwValidationError('phone', self::ACCOUNT_NOT_ACTIVE_MESSAGE);
        }

        // $requestedModuleType = UserModuleType::from($request->validated('moduleType'));
        // if ($user->module_type !== $requestedModuleType) {
        //     $this->throwValidationError('phone', self::MODULE_ACCESS_DENIED_MESSAGE);
        // }

        $fcmToken = $request->validated('fcmToken');
        if (is_string($fcmToken) && $fcmToken !== '') {
            $user->forceFill([
                'fcm_token' => $fcmToken,
            ])->save();
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
            'message' => self::LOGOUT_SUCCESS_MESSAGE,
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function throwValidationError(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }
}
