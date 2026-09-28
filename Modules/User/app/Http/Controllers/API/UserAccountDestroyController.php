<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserAccountDestroyRequest;
use Modules\User\Services\UserAccountService;

final class UserAccountDestroyController
{
    public function __invoke(
        UserAccountDestroyRequest $request,
        UserAccountService $accountService,
    ): JsonResponse {
        $accountService->deleteAccount($request->user());

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }
}
