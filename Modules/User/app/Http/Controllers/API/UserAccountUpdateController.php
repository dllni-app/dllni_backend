<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Modules\User\Http\Requests\UserAccountUpdateRequest;
use Modules\User\Services\UserAccountService;

final class UserAccountUpdateController
{
    public function __invoke(UserAccountUpdateRequest $request, UserAccountService $accountService): JsonResponse
    {
        $user = $request->user();
        $validated = Arr::except($request->validated(), ['primaryImage']);
        $primaryImage = $request->file('primaryImage');

        $updated = $accountService->updateProfile($user, $validated, $primaryImage);

        return response()->json([
            'user' => UserResource::make($updated),
        ]);
    }
}
