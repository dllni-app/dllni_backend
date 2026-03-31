<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserAccountPasswordRequest;
use Modules\User\Services\UserAccountService;

final class UserAccountPasswordController
{
    public function __invoke(UserAccountPasswordRequest $request, UserAccountService $accountService): JsonResponse
    {
        $user = $request->user();
        $accountService->updatePassword($user, $request->validated('newPassword'));

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
